<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\CapabilityManager;
use App\Lab\InstalledVersions;
use App\Lab\LabSession;
use App\Lab\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Prism\Prism\Text\Step;
use Throwable;

final class ChatController extends Controller
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function show(Request $request): Response
    {
        $sessions = app(LabSession::class);
        $capabilities = app(CapabilityManager::class);
        $session = $sessions->resolve($request);

        return Inertia::render('Lab/Chat', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'providers' => $this->providers->all(),
            'undescribed' => $this->providers->undescribed(),
            'harness' => ['mode' => $session->mode() ?? 'chat', 'run' => $session->run()],
            'capabilities' => $capabilities->status($session),
            'phoenixUrl' => rtrim((string) env('PHOENIX_UI_URL', 'http://localhost:6006'), '/'),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $input = $request->validate([
            'provider' => ['required', Rule::in($this->providers->textProviderKeys())],
            'model' => ['required', 'string', 'max:120'],
            'feature' => ['required', 'in:text,tools,research'],
            'prompt' => ['required', 'string', 'max:12000'],
        ]);

        if (! $this->providers->isConfigured($input['provider'])) {
            return response()->json(['message' => $this->providers->setupHint($input['provider']), 'missing_key' => true], 422);
        }

        $sessions = app(LabSession::class);
        $capabilities = app(CapabilityManager::class);

        $started = hrtime(true);
        $session = $sessions->resolve($request)
            ->usingProvider($input['provider'])
            ->usingModel($input['model'])
            ->usingMode($input['feature'] === 'research' ? 'research' : 'chat');

        try {
            $result = $session->send($input['prompt']);
            $response = $result->response;

            return response()->json([
                'text' => $response->text,
                'run_id' => $result->runId,
                'harness' => ['mode' => $session->mode(), 'state' => $session->run(), 'capabilities' => $capabilities->status($session)],
                'metrics' => [
                    'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'provider_reported_cost' => $response->usage->cost,
                    'cost_source' => $response->usage->cost === null ? 'unpriced' : 'provider_reported',
                    'steps' => $response->steps->count(),
                    'tool_calls' => $response->steps->sum(fn (Step $step): int => count($step->toolCalls)),
                    'finish_reason' => $response->finishReason->value,
                ],
                'phoenix_url' => rtrim((string) env('PHOENIX_UI_URL', 'http://localhost:6006'), '/'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Harness run failed. Check the application log for details.',
                'harness' => ['mode' => $session->mode(), 'state' => $session->run()],
            ], 502);
        }
    }
}
