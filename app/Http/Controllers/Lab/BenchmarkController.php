<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkFuse;
use App\Benchmarks\BenchmarkPreflight;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\LaneWorkspace;
use App\Http\Controllers\Controller;
use App\Lab\BenchmarkStore;
use App\Lab\InstalledVersions;
use App\Learnings\Learning;
use App\Learnings\LearningStore;
use App\Learnings\Severity;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class BenchmarkController extends Controller
{
    public function show(BenchmarkStore $benchmarks): Response
    {
        return Inertia::render('Lab/Benchmarks', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'specs' => BenchmarkSpec::query()->latest()->limit(20)->get(),
            'runs' => BenchmarkRun::query()->with('spec')->latest()->limit(20)->get(),
            'providerAggregateCount' => $benchmarks->all()->count(),
        ]);
    }

    public function runRoom(BenchmarkRun $run): Response
    {
        $run->load(['spec', 'lanes']);
        $workflowIds = $run->lanes->pluck('workflow_run_id')->filter()->values();
        $queued = $run->lanes->where('status', 'queued')->count();
        $running = $run->lanes->where('status', 'running')->values();
        $oldestJob = DB::table('jobs')->where('queue', 'default')->min('available_at');
        $queueAge = is_numeric($oldestJob) ? max(0, now()->timestamp - (int) $oldestJob) : null;

        // Each lane carries when it was last heard from, so the page can say
        // whether an agent is WORKING or STUCK. Without it a lane reads
        // "Agent is working" from the second it is claimed until the moment it
        // fails, and the two states are indistinguishable — which is exactly
        // how a six-minute lane that had already stopped producing looked.
        $this->attachHeartbeats($run->lanes);

        $settled = $run->lanes->whereIn('status', ['completed', 'failed', 'cancelled'])->count();
        $worker = match (true) {
            $running->isNotEmpty() => [
                'state' => 'active',
                // Named by POSITION, not by what is left behind it. "0 lane(s)
                // remain queued" is true of the last lane of ten and of a run
                // with one lane, and tells a reader neither how far along the
                // run is nor how much is left.
                'message' => sprintf(
                    'Lane %d of %d running on %s · %s. %d finished, %d still queued.',
                    $running->first()->ordinal, $run->lanes->count(),
                    $running->first()->provider, $running->first()->model,
                    $settled, $queued,
                ),
            ],
            $queued > 0 && $queueAge !== null && $queueAge >= 15 => [
                'state' => 'stalled',
                'message' => sprintf('No worker has claimed the queue for %d seconds. %d of %d lanes are done and the run is not progressing.', $queueAge, $settled, $run->lanes->count()),
            ],
            $queued > 0 => [
                'state' => 'starting',
                'message' => 'Lane workspaces are ready. Waiting briefly for the workflow worker to claim the first lane.',
            ],
            default => ['state' => 'settled', 'message' => 'No lane is waiting for the workflow worker.'],
        };

        return Inertia::render('Lab/RunRoom', [
            'run' => $run,
            'worker' => $worker,
            'flows' => DB::table('fancy_flow_workflow_runs')->whereIn('id', $workflowIds)->get([
                'id', 'run_key', 'status', 'awaiting_node', 'awaiting_kind', 'error', 'created_at', 'updated_at',
            ])->keyBy('id'),
            'nodes' => DB::table('fancy_flow_workflow_run_nodes')->whereIn('run_key', function ($query) use ($workflowIds): void {
                $query->select('run_key')->from('fancy_flow_workflow_runs')->whereIn('id', $workflowIds);
            })->orderBy('updated_at')->get([
                'run_key', 'node_id', 'status', 'attempts', 'error', 'claimed_at', 'completed_at', 'updated_at',
            ]),
        ]);
    }

    /**
     * Stamp each lane with the last moment anything was recorded for it.
     *
     * Read from BOTH stores: `benchmark_lane_activities` carries the narrated
     * milestones and `lab_operations` carries the tool calls, and an agent deep
     * in a build emits the second for minutes without emitting the first. Using
     * activities alone would report a busy lane as silent.
     *
     * @param  Collection<int, BenchmarkLane>  $lanes
     */
    private function attachHeartbeats($lanes): void
    {
        $ids = $lanes->pluck('id');
        $activity = DB::table('benchmark_lane_activities')->whereIn('benchmark_lane_id', $ids)
            ->groupBy('benchmark_lane_id')->pluck(DB::raw('max(created_at)'), 'benchmark_lane_id');
        $operations = DB::table('lab_operations')->whereIn('benchmark_lane_id', $ids)
            ->groupBy('benchmark_lane_id')->pluck(DB::raw('max(started_at)'), 'benchmark_lane_id');

        foreach ($lanes as $lane) {
            $latest = collect([$activity[$lane->id] ?? null, $operations[$lane->id] ?? null])
                ->filter()->map(fn ($value): string => (string) $value)->max();

            $lane->setAttribute('last_seen_at', $latest);
        }
    }

    public function lane(BenchmarkRun $run, BenchmarkLane $lane, LaneWorkspace $workspace): JsonResponse
    {
        abort_unless($lane->benchmark_run_id === $run->id, 404);

        return response()->json([
            'lane' => $lane,
            'activities' => $lane->activities()->latest('id')->limit(500)->get()->reverse()->values(),
            'operations' => DB::table('lab_operations')->where('benchmark_lane_id', $lane->id)->orderBy('started_at')->limit(500)->get()->map(function ($operation) {
                if (is_string($operation->metadata)) {
                    $decoded = json_decode($operation->metadata, true);
                    $operation->metadata = is_array($decoded) ? $decoded : null;
                }

                return $operation;
            }),
            'files' => $workspace->files($lane),
        ]);
    }

    public function laneFile(Request $request, BenchmarkRun $run, BenchmarkLane $lane, LaneWorkspace $workspace): JsonResponse
    {
        abort_unless($lane->benchmark_run_id === $run->id, 404);
        $input = $request->validate(['path' => ['required', 'string', 'max:1000']]);

        return response()->json($workspace->read($lane, $input['path']));
    }

    public function laneMedia(Request $request, BenchmarkRun $run, BenchmarkLane $lane, LaneWorkspace $workspace): BinaryFileResponse
    {
        abort_unless($lane->benchmark_run_id === $run->id, 404);
        $input = $request->validate(['path' => ['required', 'string', 'max:1000']]);
        $media = $workspace->media($lane, $input['path']);

        return response()->file($media['absolute'], [
            'Content-Type' => $media['mime'],
            'Content-Disposition' => 'inline; filename="'.basename($media['path']).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function specification(BenchmarkSpec $spec): Response
    {
        return Inertia::render('Lab/SpecificationReview', ['spec' => $spec]);
    }

    public function store(Request $request, BenchmarkDesigner $designer): RedirectResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'outcome' => ['required', 'string', 'max:4000'],
            'acceptance' => ['required', 'string', 'max:8000'],
            'surface_mode' => ['required', 'in:standard,human_plus'],
            'provider' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:160'],
            'cost_cap' => ['required', 'numeric', 'min:0.01', 'max:10000'],
        ]);

        $lanes = array_map(fn (array $lane): array => [
            ...$lane, 'provider' => $input['provider'], 'model' => $input['model'],
        ], [
            ['language' => 'php', 'harness' => 'prism-harness'],
            ['language' => 'typescript', 'harness' => 'prism-ts-harness'],
            ['language' => 'python', 'harness' => 'prism-py-harness'],
        ]);

        $spec = $designer->draft(
            $input['name'], 'typescript-sqlite-fancy', $input['surface_mode'],
            ['outcome' => $input['outcome'], 'acceptance' => preg_split('/\r?\n/', $input['acceptance']) ?: []],
            ['functional' => 40, 'fancy_fidelity' => 25, 'human_plus' => 20, 'quality' => 15],
            $lanes, ['cost' => (float) $input['cost_cap']],
        );

        return to_route('lab.benchmark-specs.show', $spec);
    }

    public function requestApproval(BenchmarkSpec $spec, BenchmarkDesigner $designer): RedirectResponse
    {
        $designer->requestApproval($spec);

        return to_route('lab.benchmark-specs.show', $spec);
    }

    public function approve(BenchmarkSpec $spec, BenchmarkDesigner $designer): RedirectResponse
    {
        $designer->approve($spec);

        return to_route('lab.benchmark-specs.show', $spec);
    }

    public function launch(BenchmarkSpec $spec, BenchmarkDesigner $designer, BenchmarkWorkflow $workflow, BenchmarkPreflight $preflight, LearningStore $learnings): RedirectResponse
    {
        $failures = $preflight->failures($spec);
        if ($failures !== []) {
            // A refused launch files a 0L too.
            //
            // Every terminal RUN files one, but a preflight refusal never
            // creates a run — so without this, the single most informative
            // outcome the Lab produces (the benchmark cannot start at all, and
            // here is precisely why) is the one outcome that left no trace.
            // A learning is owed for every test, and "it could not run" is a
            // finding about the ecosystem rather than an absence of one.
            $this->recordRefusedLaunch($learnings, $spec, $failures);

            return to_route('lab.benchmark-specs.show', $spec)->with('error', 'Benchmark preflight failed: '.implode(' ', $failures));
        }

        $run = $designer->launch($spec);
        $workflow->dispatch($run);

        return to_route('lab.benchmark-runs.show', $run);
    }

    /**
     * @param  list<string>  $failures
     */
    private function recordRefusedLaunch(LearningStore $learnings, BenchmarkSpec $spec, array $failures): void
    {
        $title = sprintf('%s r%d — refused before any lane started', $spec->name, $spec->revision);

        // One learning per spec revision per refusal, not one per click. The
        // Launch button is right there and a blocked operator will press it
        // again; a 0L per press buries the finding under copies of itself.
        if (Learning::query()->where('title', $title)->exists()) {
            return;
        }

        try {
            $learnings->file(
                title: $title,
                filedBy: 'prism-lab/benchmark',
                languages: array_values(array_unique(array_column($spec->lane_matrix, 'language'))),
                whatWasLearned: "The preflight refused this benchmark, so no lane ran and the spec answered nothing about the languages it compares.\n\n".
                    'That refusal is the finding. The spec is approved and frozen, the lanes are well formed, and the run still cannot start — so what is missing is capability in the ecosystem, not correctness in the specification.',
                evidence: implode("\n", array_map(fn (string $failure): string => '- '.$failure, $failures)),
                whyItMatters: 'A benchmark that cannot start looks like nothing happened, and nothing happening leaves no record. The cost of designing a fair spec has already been paid by the time the preflight speaks, so losing the refusal means paying it again to reach the same wall.',
                whatShouldChange: 'Read the failures above as a capability gap. If a lane names an agent that does not offer the tool the lane needs, adding an endpoint that returns "not implemented" would move the failure later and say less than this refusal already does — the missing piece is the contract behind it.',
                severity: Severity::Urgent,
            );
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    public function stop(BenchmarkRun $run, BenchmarkFuse $fuse): RedirectResponse
    {
        $fuse->trip($run);

        return to_route('lab.benchmark-runs.show', $run)->with('status', 'Benchmark fuse tripped. No queued lane can start.');
    }

    public function destroy(BenchmarkRun $run, BenchmarkFuse $fuse): RedirectResponse
    {
        if (in_array($run->status, ['queued', 'ready', 'running'], true)) {
            return to_route('lab.benchmark-runs.show', $run)->with('error', 'Stop the run before deleting it. The emergency stop is available while the run is active.');
        }

        $fuse->purge($run);

        return to_route('lab.benchmarks')->with('status', 'Benchmark run and its workflow data were deleted.');
    }

    public function clear(Request $request, BenchmarkFuse $fuse): RedirectResponse
    {
        $input = $request->validate(['scope' => ['required', 'in:queued,settled']]);
        $count = $fuse->clear($input['scope']);

        return to_route('lab.benchmarks')->with('status', sprintf('%d %s benchmark run(s) deleted.', $count, $input['scope']));
    }

    /**
     * Publishable artifact — the aggregated comparison plus its provenance.
     */
    public function export(BenchmarkStore $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->export(), 200, [
            'Content-Disposition' => 'attachment; filename="prism-benchmarks.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
