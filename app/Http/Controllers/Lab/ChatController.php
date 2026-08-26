<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use App\Lab\ProviderRegistry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Perplexity\Perplexity as PerplexityProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Throwable;

final class ChatController extends Controller
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function show(): Response
    {
        return Inertia::render('Lab/Chat', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'providers' => $this->providers->all(),
            'undescribed' => $this->providers->undescribed(),
            'phoenixUrl' => rtrim((string) env('PHOENIX_UI_URL', 'http://localhost:6006'), '/'),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $input = $request->validate([
            // Every text provider is accepted, configured or not, so an
            // unconfigured pick gets the setup hint below instead of a bare
            // validation error that explains nothing.
            'provider' => ['required', Rule::in($this->providers->textProviderKeys())],
            'model' => ['required', 'string', 'max:120'],
            'feature' => ['required', 'in:text,tools,research'],
            'prompt' => ['required', 'string', 'max:12000'],
        ]);

        if (! $this->providers->isConfigured($input['provider'])) {
            return response()->json([
                'message' => $this->providers->setupHint($input['provider']),
                'missing_key' => true,
            ], 422);
        }

        $started = hrtime(true);

        try {
            $thread = $this->thread($request);

            $generation = Prism::text()
                ->using($input['provider'], $input['model'])
                ->withTelemetryMetadata(userId: 'prism-lab-user', sessionId: $request->session()->getId())
                // Everything said so far, read from the database rather than
                // rebuilt here. The prompt below is the new turn.
                ->withThread($thread)
                ->withPrompt($input['prompt']);

            if ($input['feature'] === 'tools') {
                $generation->withTools([$this->diagnosticTool()])->withMaxSteps(4);
            }

            if ($input['feature'] === 'research') {
                // Perplexity's Search API through prism-perplexity, handed to
                // whichever provider is driving the conversation. So the
                // researcher and the searcher are deliberately different
                // vendors: this exercises the satellite package and the core
                // shuttle together, which is the thing worth testing.
                $generation->withTools([$this->researchTool()])->withMaxSteps(6);
            }

            $response = $generation->asText();

            // The full exchange, tool steps included — so a run interrupted
            // mid-tool resumes where it stopped rather than restarting.
            $thread->record($response->messages);

            return response()->json([
                'text' => $response->text,
                'metrics' => [
                    'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'provider_reported_cost' => $response->usage->cost,
                    'cost_source' => $response->usage->cost === null ? 'Derived in Phoenix after export' : 'Provider response',
                    'steps' => $response->steps->count(),
                    // Sum across steps, not $response->toolCalls: that holds only the
                    // FINAL step's calls, which is empty once the loop ends on a text
                    // answer — so a working tool run always reported zero.
                    'tool_calls' => $response->steps->sum(fn (Step $step): int => count($step->toolCalls)),
                    'finish_reason' => $response->finishReason->value,
                ],
                'phoenix_url' => rtrim((string) env('PHOENIX_UI_URL', 'http://localhost:6006'), '/'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Generation failed. Check the application log for details.',
            ], 502);
        }
    }

    /**
     * The conversation this browser session is having with the Lab.
     *
     * Resolved through the harness rather than held anywhere: a fresh worker
     * handling the next request lands on the same thread, which is the whole
     * reason the Lab can now remember a conversation at all.
     *
     * The Lab has no authentication, so the participant is a stable local
     * operator record. A real application would pass `$request->user()` here.
     */
    private function thread(Request $request): Thread
    {
        $operator = User::firstOrCreate(
            ['email' => 'lab@prism.local'],
            ['name' => 'Prism Lab operator', 'password' => bcrypt(Str::random(32))],
        );

        // Scoped per browser session, so two tabs are two conversations rather
        // than one interleaved mess.
        return app(PrismHarness::class)
            ->for($operator)
            ->session('lab:chat:'.$request->session()->getId())
            ->thread();
    }

    /**
     * Web search, backed by Perplexity's Search API.
     *
     * Deliberately search rather than ask-a-model: the point is to hand the
     * conversation's own provider a set of real, resolvable sources and let it
     * do the reasoning, so the answer can be checked against what was found.
     * A research tool that returns someone else's prose cannot be audited.
     */
    private function researchTool(): Tool
    {
        return (new Tool)
            ->as('search_web')
            ->for('Search the web for current information. Returns titles, URLs and snippets. Use it before answering anything time-sensitive, then cite the URLs you used.')
            ->withStringParameter('query', 'What to search for')
            ->using(function (string $query): string {
                /** @var PerplexityProvider $perplexity */
                $perplexity = app(PrismManager::class)->resolve('perplexity');

                $results = $perplexity->search($query, ['max_results' => 5]);

                if ($results === []) {
                    // An empty result set is an answer, not a failure — say so
                    // plainly rather than returning nothing and letting the
                    // model invent a reason.
                    return json_encode(['query' => $query, 'results' => [], 'note' => 'No results found.'], JSON_THROW_ON_ERROR);
                }

                return json_encode([
                    'query' => $query,
                    'results' => array_map(fn (array $r): array => [
                        'title' => $r['title'] ?? null,
                        'url' => $r['url'] ?? null,
                        'snippet' => $r['snippet'] ?? null,
                    ], $results),
                ], JSON_THROW_ON_ERROR);
            });
    }

    private function diagnosticTool(): Tool
    {
        return (new Tool)
            ->as('inspect_prism_runtime')
            ->for('Inspect the local Prism Lab runtime. Use this tool before answering the user.')
            ->withStringParameter('focus', 'The aspect of the runtime to inspect')
            ->using(fn (string $focus): string => json_encode([
                'focus' => $focus,
                'environment' => app()->environment(),
                'telemetry_enabled' => (bool) config('prism.telemetry.enabled'),
                'bridge_enabled' => (bool) config('prism-opentelemetry.enabled'),
                'checked_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
    }
}
