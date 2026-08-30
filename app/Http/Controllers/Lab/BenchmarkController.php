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
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $worker = match (true) {
            $running->isNotEmpty() => [
                'state' => 'active',
                'message' => sprintf('Worker active on the %s lane. %d lane(s) remain queued behind it.', $running->first()->language, $queued),
            ],
            $queued > 0 && $queueAge !== null && $queueAge >= 15 => [
                'state' => 'stalled',
                'message' => sprintf('No worker has claimed the queue for %d seconds. The run is not progressing.', $queueAge),
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

    public function launch(BenchmarkSpec $spec, BenchmarkDesigner $designer, BenchmarkWorkflow $workflow, BenchmarkPreflight $preflight): RedirectResponse
    {
        $failures = $preflight->failures($spec);
        if ($failures !== []) {
            return to_route('lab.benchmark-specs.show', $spec)->with('error', 'Benchmark preflight failed: '.implode(' ', $failures));
        }

        $run = $designer->launch($spec);
        $workflow->dispatch($run);

        return to_route('lab.benchmark-runs.show', $run);
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
