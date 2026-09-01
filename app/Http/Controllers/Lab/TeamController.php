<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Conformance\ConformanceRun;
use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use App\Learnings\Learning;
use App\Team\AgentRoster;
use App\Team\Coordinator;
use App\Team\LanguageAgent;
use App\Team\Nudge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The board: who is on the team, what they have learned, and a way to talk to
 * the coordinator directly.
 *
 * The roster is NOT probed on page load. Probing means a network call per
 * addressable lane, and a lane that is down would hold the whole page behind
 * its timeout. The page renders the roster's declared shape immediately and
 * asks for live state separately, so a dead agent costs one card rather than
 * the board.
 */
final class TeamController extends Controller
{
    public function show(Coordinator $coordinator): Response
    {
        return Inertia::render('Lab/Team', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'learnings' => $this->learnings(),
            'parity' => $this->parity(),
        ]);
    }

    /**
     * The most recent cross-language conformance run.
     *
     * Read, never run. A conformance run builds each port and can take
     * minutes; doing that on a page load would make the board look hung. It is
     * triggered deliberately — `php artisan team:conformance`.
     *
     * @return array<string, mixed>|null
     */
    private function parity(): ?array
    {
        $run = ConformanceRun::query()->latest('id')->first();

        if ($run === null) {
            return null;
        }

        $totals = [];

        foreach ($run->results as $result) {
            $totals[$result->language][$result->status] = ($totals[$result->language][$result->status] ?? 0) + 1;
        }

        return [
            'corpus_version' => $run->corpus_version,
            'corpus_digest' => $run->corpus_digest,
            'ran_at' => $run->created_at?->diffForHumans(),
            'totals' => $totals,
            // Per case, because totals agree even when the languages do not —
            // which is exactly what the first run found.
            'disagreements' => $run->disagreements(),
        ];
    }

    /**
     * Live state for every lane. Called after paint, and on demand.
     */
    public function roster(Coordinator $coordinator): JsonResponse
    {
        return response()->json(['roster' => $coordinator->roster()]);
    }

    /**
     * The harness ports, exercised END TO END in both languages.
     *
     * Asked of the AGENTS rather than run here, which is the point: the Lab is
     * a consumer reaching a package over the wire in a process that did not
     * build it. Running the same scenario inside this app would prove that PHP
     * can call PHP.
     *
     * Not probed on page load, for the same reason the roster is not — two
     * network calls, and a lane that is down would hold the board behind its
     * timeout.
     */
    public function harness(AgentRoster $roster): JsonResponse
    {
        $lanes = [];

        foreach ($roster->addressable() as $agent) {
            $probe = (new LanguageAgent($agent))->call('harness_probe', [], (float) config('team.timeouts.work'));

            $lanes[] = [
                'agent' => $agent->name,
                'language' => $agent->language,
                'reachable' => $probe['ok'] === true,
                'reason' => $probe['reason'] ?? null,
                'report' => $probe['data'] ?? null,
            ];
        }

        // The claim worth surfacing: every reachable lane produced the SAME
        // session address. That is what lets a PHP app and a TypeScript or
        // Python agent resolve one conversation, and it is checked here rather
        // than described because a drift would otherwise be invisible.
        $keys = array_values(array_unique(array_filter(array_map(
            fn (array $lane): ?string => $lane['report']['session_key'] ?? null,
            $lanes,
        ))));

        return response()->json([
            'lanes' => $lanes,
            'shared_session_key' => count($keys) === 1 ? $keys[0] : null,
            'keys_agree' => count($keys) === 1,
        ]);
    }

    public function ask(Request $request, Coordinator $coordinator): JsonResponse
    {
        // Validated by hand rather than through $request->validate(). This route
        // is in the web group, so Inertia turns a validation failure into a 302
        // back to the page — right for a form post, wrong for a JSON endpoint
        // the page calls with fetch().
        $validator = Validator::make($request->all(), [
            'question' => ['required', 'string', 'max:4000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('question')], 422);
        }

        try {
            $result = $coordinator->ask((string) $validator->validated()['question']);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Prism could not complete that: '.$e->getMessage(),
            ], 502);
        }

        return response()->json([
            ...$result,
            // Re-read rather than returned from the run: the coordinator may
            // have filed one mid-answer, and the feed should show it without
            // the page having to guess whether it did.
            'learnings' => $this->learnings(),
        ]);
    }

    /**
     * Hand one 0L to the coding agent working in this workspace.
     *
     * Deliberately per-report and manual. A board that nudged on every filing
     * would train the person receiving them to ignore the channel, which costs
     * more than the feature is worth.
     */
    public function nudge(Request $request, Nudge $nudge): JsonResponse
    {
        $learning = Learning::query()->where('ref', (string) $request->input('ref'))->first();

        if ($learning === null) {
            return response()->json(['message' => 'No 0L with that reference.'], 404);
        }

        $result = $nudge->send($learning);

        if (($result['ok'] ?? false) !== true) {
            return response()->json(['message' => $result['reason'] ?? 'Could not deliver it.'], 502);
        }

        return response()->json(['message' => "Sent {$learning->ref} to the agent in this workspace."]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function learnings(): array
    {
        return Learning::query()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Learning $l): array => [
                'ref' => $l->ref,
                'title' => $l->title,
                'filed_by' => $l->filed_by,
                'severity' => $l->severity->value,
                'severity_label' => $l->severity->label(),
                'languages' => $l->languages,
                'what_was_learned' => $l->what_was_learned,
                'evidence' => $l->evidence,
                'why_it_matters' => $l->why_it_matters,
                'what_should_change' => $l->what_should_change,
                'filed_at' => $l->created_at?->diffForHumans(),
            ])
            ->all();
    }
}
