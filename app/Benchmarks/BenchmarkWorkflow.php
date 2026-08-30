<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

final readonly class BenchmarkWorkflow
{
    public function __construct(private FancyFlowManager $flow, private LaneWorkspace $workspaces, private LaneActivity $activity) {}

    public function dispatch(BenchmarkRun $run): void
    {
        $lanes = BenchmarkLane::query()->where('benchmark_run_id', $run->id)->orderBy('ordinal')->get();
        $firstWorkflowId = null;

        foreach ($lanes as $lane) {
            $path = $this->workspaces->provision($lane);
            $this->activity->record($lane, 'lane.queued', 'Lane queued and isolated workspace provisioned.', ['workspace' => $path]);
            $node = new FlowNode(
                id: 'lane-'.$lane->ordinal,
                type: '@prism-lab/benchmark_lane',
                label: ucfirst($lane->language).' benchmark lane',
                config: ['lane_id' => $lane->id],
                outputs: [],
            );
            $workflow = $this->flow->dispatch(new FlowGraph([$node]), maxConcurrent: 1);
            $firstWorkflowId ??= $workflow->id;
            $lane->forceFill(['workflow_run_id' => $workflow->id])->save();
        }

        $run->forceFill(['workflow_run_id' => $firstWorkflowId, 'status' => 'running', 'started_at' => now()])->save();
    }
}
