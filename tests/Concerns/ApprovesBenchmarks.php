<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkWork;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\ProofRecorder;
use App\Jobs\CallTheRunJob;
use App\Lab\PaidRunContext;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Models\LabPaidWork;
use App\Models\User;
use App\Providers\PrismLabServiceProvider;
use Closure;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Prism\Workspace\WorkspaceManager;

/** Native synthetic approvals and isolated workspaces; no authority bypass. */
trait ApprovesBenchmarks
{
    use ApprovesLabRuns;
    use CachesModelRoster;

    private ?QueueManager $benchmarkRealQueue = null;

    protected function prepareBenchmarkAuthority(): void
    {
        if ($this->benchmarkRealQueue !== null) {
            return;
        }
        $this->prepareLabAuthority();
        $this->benchmarkRealQueue = app('queue');
        $this->assertInstanceOf(QueueManager::class, $this->benchmarkRealQueue);
        config(['filesystems.disks.g246-owned-benchmark' => ['driver' => 'local',
            'root' => $this->authorityFixtureDirectory.'/workspaces', 'throw' => true],
            'prism-workspace.disk' => 'g246-owned-benchmark', 'prism-workspace.root' => 'w',
            'prism-browser.sidecar.token' => str_repeat('owned-synthetic-browser-', 2)]);
        $this->app->forgetInstance(WorkspaceManager::class);
        $this->cacheModelRoster();
        // Invoke the actual non-I/O application boot registrations. Its
        // register() also builds a real local OTLP exporter, which this owned
        // fixture deliberately never creates or contacts.
        if ($this->app->getProvider(PrismLabServiceProvider::class) === null) {
            $provider = new PrismLabServiceProvider($this->app);
            $provider->bindLocalServices();
            $provider->boot();
        }
    }

    protected function approveBenchmarkSpec(?array $lanes = null): BenchmarkSpec
    {
        $this->prepareBenchmarkAuthority();
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $lanes ??= [['language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5']];
        DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'],
            ['value' => json_encode(array_values(array_unique(array_map(fn ($lane) => $lane['provider'].':'.$lane['model'], $lanes))), JSON_THROW_ON_ERROR)]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            $designer = app(BenchmarkDesigner::class);
            $spec = $designer->draft('Owned workflow '.BenchmarkSpec::query()->count(), 'application', 'standard',
                ['outcome' => 'Build an owned fixture', 'acceptance' => ['Works']], ['quality' => 100], $lanes, ['cost' => 1]);

            return $designer->approve($designer->requestApproval($spec));
        } finally {
            auth('web')->logout();
        }
    }

    protected function withBenchmarkRun(Closure $operation, ?array $lanes = null): mixed
    {
        $spec = $this->approveBenchmarkSpec($lanes);

        return $this->withApprovedLabRun('benchmarks.launch', [], function () use ($operation, $spec): mixed {
            $run = app(BenchmarkDesigner::class)->launch($spec);

            return $operation($run);
        }, $spec->id);
    }

    protected function dispatchedBenchmark(?array $lanes = null): BenchmarkRun
    {
        return $this->withBenchmarkRun(function (BenchmarkRun $run): BenchmarkRun {
            app(BenchmarkWorkflow::class)->dispatch($run);

            return $run->refresh();
        }, $lanes);
    }

    /** Keep a legacy test's exact brief, rubric and model choices. */
    protected function dispatchApprovedDraft(BenchmarkSpec $spec): BenchmarkRun
    {
        $this->prepareBenchmarkAuthority();
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            $designer = app(BenchmarkDesigner::class);
            $spec = $designer->approve($designer->requestApproval($spec));
        } finally {
            auth('web')->logout();
        }

        return $this->withApprovedLabRun('benchmarks.launch', [], function () use ($spec): BenchmarkRun {
            $run = app(BenchmarkDesigner::class)->launch($spec);
            app(BenchmarkWorkflow::class)->dispatch($run);
            $work = BenchmarkWork::bindCommentary($run, 1);
            CallTheRunJob::dispatch($run->id, $work->id)->afterCommit();

            return $run->refresh();
        }, $spec->id);
    }

    protected function beginBenchmarkJob(string $queue): void
    {
        $publisher = app('queue');
        if ($publisher instanceof QueueFake) {
            Queue::swap($this->benchmarkRealQueue);
        }
        try {
            $this->beginApprovedJob($queue);
        } finally {
            Queue::swap($publisher);
        }
    }

    /** A synthetic builder submits real bound proof; no model is invoked here. */
    protected function submitBenchmarkProof(BenchmarkLane $lane, array $proof, array $receipts): LabPaidWork
    {
        $this->beginBenchmarkJob('default');

        return app(PaidWorkRegistry::class)->run($lane->paid_work_id, 'benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane),
            function () use ($lane, $proof, $receipts): LabPaidWork {
                app(PaidRunContext::class)->requireWork('benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane));
                $lane->forceFill(['status' => 'running', 'started_at' => now()])->save();
                app(ProofRecorder::class)->complete($lane, $proof, $receipts);

                return BenchmarkWork::bindScore($lane);
            });
    }

    /** A synthetic builder submits real bound proof; no model is invoked here. */
    protected function boundBenchmarkScore(BenchmarkLane $lane): LabPaidWork
    {
        $this->beginBenchmarkJob('default');

        return app(PaidWorkRegistry::class)->run($lane->paid_work_id, 'benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane),
            function () use ($lane): LabPaidWork {
                app(PaidRunContext::class)->requireWork('benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane));
                $lane->forceFill(['status' => 'running', 'started_at' => now()])->save();
                app(ProofRecorder::class)->complete($lane, ['spec_digest' => $lane->benchmarkRun->spec->digest,
                    'working_artifact' => 'owned.txt', 'checks' => ['quality' => true], 'zero_learning' => 'Owned fixture'],
                    [['kind' => 'owned-check', 'payload' => ['pass' => true]]]);

                return BenchmarkWork::bindScore($lane);
            });
    }
}
