<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkLaneExecutor;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\LaneWorkspace;
use App\Jobs\CallTheRunJob;
use App\Jobs\ScoreLaneJob;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\LabPaidWork;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Jobs\RunNodeJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Schema\FlowNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

final class BenchmarkWorkflowAuthorityTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
    }

    private function contextFor(BenchmarkLane $lane, ?string $runKey = null, bool $includeReference = true): ExecutionContext
    {
        $workflow = WorkflowRun::query()->findOrFail($lane->workflow_run_id);
        $node = app(FancyFlowManager::class)->toGraph($workflow->schema)->nodes[0];
        if (! $includeReference) {
            $configuration = $node->config;
            unset($configuration['paid_work_id']);
            $node = new FlowNode($node->id, $node->type, config: $configuration);
        }

        return new ExecutionContext($node, [], fn () => null,
            run: new RunIdentity($runKey ?? $workflow->run_key));
    }

    public function test_dispatch_without_its_parent_context_creates_no_workflow_or_workspace(): void
    {
        Queue::fake();
        $run = $this->withBenchmarkRun(fn (BenchmarkRun $run) => $run);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkWorkflow::class)->dispatch($run);
        } finally {
            $this->assertSame(0, WorkflowRun::query()->count());
            $this->assertNull($run->lanes()->firstOrFail()->workspace_path);
            Queue::assertNothingPushed();
        }
    }

    public function test_dispatch_carries_the_actual_work_reference_in_each_native_flow_node(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        $workflow = WorkflowRun::query()->findOrFail($lane->workflow_run_id);
        $this->assertSame(['lane_id' => $lane->id, 'paid_work_id' => $lane->paid_work_id], $workflow->schema['graph']['nodes'][0]['config']);
        $this->assertNotNull(LabPaidWork::query()->findOrFail($run->paid_work_id)->claimed_at);
        $this->assertNull(LabPaidWork::query()->findOrFail($lane->paid_work_id)->claimed_at);
        Queue::assertPushed(AdvanceWorkflowJob::class, 1);
    }

    public function test_duplicate_dispatch_cannot_create_a_second_flow(): void
    {
        Queue::fake();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withBenchmarkRun(function (BenchmarkRun $run): void {
                app(BenchmarkWorkflow::class)->dispatch($run);
                app(BenchmarkWorkflow::class)->dispatch($run);
            });
        } finally {
            $this->assertSame(1, WorkflowRun::query()->count());
            Queue::assertPushed(AdvanceWorkflowJob::class, 1);
        }
    }

    public function test_a_changed_lane_target_refuses_before_any_workspace_or_flow(): void
    {
        Queue::fake();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withBenchmarkRun(function (BenchmarkRun $run): void {
                $run->lanes()->firstOrFail()->forceFill(['model' => 'unreviewed-model'])->save();
                app(BenchmarkWorkflow::class)->dispatch($run);
            });
        } finally {
            $this->assertSame(0, WorkflowRun::query()->count());
            $this->assertSame(0, BenchmarkLane::query()->whereNotNull('workspace_path')->count());
            Queue::assertNothingPushed();
        }
    }

    public function test_a_missing_lane_binding_rolls_back_dispatch_without_provisioning(): void
    {
        Queue::fake();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withBenchmarkRun(function (BenchmarkRun $run): void {
                $run->lanes()->firstOrFail()->forceFill(['paid_work_id' => null])->save();
                app(BenchmarkWorkflow::class)->dispatch($run);
            });
        } finally {
            $this->assertSame(0, WorkflowRun::query()->count());
            $this->assertSame(0, BenchmarkLane::query()->whereNotNull('workspace_path')->count());
            $this->assertNull(LabPaidWork::query()->where('kind', 'benchmark.run')->sole()->claimed_at);
        }
    }

    public function test_another_flow_identity_cannot_spend_the_lanes_work(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([]);
        $this->beginBenchmarkJob('default');
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkLaneExecutor::class)->execute($this->contextFor($lane, 'other-flow'));
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull(LabPaidWork::query()->findOrFail($lane->paid_work_id)->claimed_at);
        }
    }

    public function test_a_legacy_node_without_its_reference_refuses_before_the_sdk(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([]);
        $this->beginBenchmarkJob('default');
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkLaneExecutor::class)->execute($this->contextFor($lane, includeReference: false));
        } finally {
            $fake->assertCallCount(0);
        }
    }

    public function test_revocation_after_dispatch_stops_the_actual_executor(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($run->approved_run_id);
        auth('web')->logout();
        $fake = Prism::fake([]);
        $this->beginBenchmarkJob('default');
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkLaneExecutor::class)->execute($this->contextFor($lane));
        } finally {
            $fake->assertCallCount(0);
            $this->assertSame('queued', $lane->refresh()->status);
        }
    }

    public function test_the_actual_flow_job_runs_once_and_dispatches_bound_scoring_without_a_login(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        // Free fixture preparation uses the native owner; the actual queued
        // executor below still runs as a guest under its approved work.
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $workspace = app(LaneWorkspace::class)->workspace($lane);
        $workspace->write('artifact.txt', 'Owned artifact');
        $workspace->write('PROOF_OF_WORKING.json', json_encode(['spec_digest' => $run->spec->digest,
            'working_artifact' => 'artifact.txt', 'checks' => ['working' => true], 'zero_learning' => 'Owned fixture',
            'receipts' => [['kind' => 'owned-check', 'payload' => ['pass' => true]]]], JSON_THROW_ON_ERROR));
        auth('web')->logout();
        $workflow = WorkflowRun::query()->findOrFail($lane->workflow_run_id);
        app()->call([new AdvanceWorkflowJob($workflow->run_key), 'handle']);
        Queue::assertPushed(RunNodeJob::class, 1);
        $job = Queue::pushed(RunNodeJob::class)->first();
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned artifact ready.')]);
        $this->assertGuest('web');
        $this->beginBenchmarkJob('default');
        app()->call([$job, 'handle']);
        $fake->assertCallCount(1);
        $this->assertSame('completed', $lane->refresh()->status);
        Queue::assertPushed(ScoreLaneJob::class, fn ($score) => $score->laneId === $lane->id
            && LabPaidWork::query()->whereKey($score->paidWorkId)->where('kind', 'benchmark.score')->where('parent_id', $lane->paid_work_id)->exists());
        app()->call([$job, 'handle']);
        $fake->assertCallCount(1);
        Queue::assertPushed(ScoreLaneJob::class, 1);
    }

    public function test_the_real_owner_go_route_dispatches_bound_flow_and_commentary_once(): void
    {
        Queue::fake();
        $spec = $this->approveBenchmarkSpec();
        [$intent] = $this->approvedLabRun('benchmarks.launch', [], $spec->id);
        $fake = Prism::fake([]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $this->withSession(['_token' => 'owned-route-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-route-csrf');
        $this->postJson('/lab/benchmarks/'.$spec->id.'/launch', ['approved_run_id' => $intent->id])->assertRedirect();
        $run = BenchmarkRun::query()->sole();
        $this->assertSame($intent->id, $run->approved_run_id);
        $this->assertSame('running', $intent->refresh()->status);
        $this->assertSame(1, WorkflowRun::query()->count());
        Queue::assertPushed(AdvanceWorkflowJob::class, 1);
        Queue::assertPushed(CallTheRunJob::class, fn ($job) => $job->sequence === 1 && $job->runId === $run->id
            && LabPaidWork::query()->whereKey($job->paidWorkId)->where('kind', 'benchmark.commentary')->where('intent_id', $intent->id)->exists());
        $this->postJson('/lab/benchmarks/'.$spec->id.'/launch', ['approved_run_id' => $intent->id])->assertForbidden();
        $this->assertSame(1, BenchmarkRun::query()->count());
        Queue::assertPushed(AdvanceWorkflowJob::class, 1);
        Queue::assertPushed(CallTheRunJob::class, 1);
        $fake->assertCallCount(0);
    }
}
