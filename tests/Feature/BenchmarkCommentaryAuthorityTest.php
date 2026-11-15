<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkCommentator;
use App\Benchmarks\BenchmarkFuse;
use App\Benchmarks\BenchmarkWork;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\LaneActivity;
use App\Jobs\CallTheRunJob;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Models\BenchmarkCommentary;
use App\Models\BenchmarkRun;
use App\Models\LabPaidWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

final class BenchmarkCommentaryAuthorityTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
        Queue::fake();
    }

    private function commentaryFixture(): array
    {
        $result = $this->withBenchmarkRun(function (BenchmarkRun $run): array {
            app(BenchmarkWorkflow::class)->dispatch($run);

            return [$run, BenchmarkWork::bindCommentary($run, 1)];
        });
        $this->beginBenchmarkJob('commentary');

        return $result;
    }

    public function test_a_direct_commentator_without_its_work_context_cannot_infer(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkCommentator::class)->call($run);
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull($work->refresh()->claimed_at);
        }
    }

    public function test_the_first_delivery_cannot_be_bound_before_actual_root_dispatch(): void
    {
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withBenchmarkRun(fn (BenchmarkRun $run) => BenchmarkWork::bindCommentary($run, 1));
        } finally {
            $this->assertSame(0, LabPaidWork::query()->where('kind', 'benchmark.commentary')->count());
        }
    }

    public function test_a_successor_cannot_be_minted_without_its_claimed_previous_delivery(): void
    {
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withBenchmarkRun(function (BenchmarkRun $run): void {
                app(BenchmarkWorkflow::class)->dispatch($run);
                BenchmarkWork::bindCommentary($run, 2);
            });
        } finally {
            $this->assertSame(0, LabPaidWork::query()->where('kind', 'benchmark.commentary')->count());
        }
    }

    public function test_legacy_jobs_do_not_pay_or_create_another_chain(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([]);
        (new CallTheRunJob($run->id))->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(0);
        Queue::assertNotPushed(CallTheRunJob::class);
        $this->assertNull($work->refresh()->claimed_at);
    }

    public function test_the_exact_delivery_pays_once_and_creates_one_bound_successor_without_login(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned first line.'), TextResponseFake::make()->withText('Owned next line.')]);
        $this->assertGuest('web');
        $job = new CallTheRunJob($run->id, $work->id);
        $job->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(1);
        $this->assertNotNull($work->refresh()->claimed_at);
        Queue::assertPushed(CallTheRunJob::class, 1);
        $next = Queue::pushed(CallTheRunJob::class)->sole();
        $this->assertSame(2, $next->sequence);
        $bound = LabPaidWork::query()->findOrFail($next->paidWorkId);
        $this->assertSame($work->intent_id, $bound->intent_id);
        $this->assertSame($run->paid_work_id, $bound->parent_id);
        $this->assertSame($run->id.':2', $bound->work_key);
        $job->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(1);
        Queue::assertPushed(CallTheRunJob::class, 1);
        app(LaneActivity::class)->record($run->lanes()->firstOrFail(), 'owned.next', 'Owned next activity');
        $next->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(2);
        Queue::assertPushed(CallTheRunJob::class, 2);
        $this->assertSame(2, BenchmarkCommentary::query()->count());
    }

    public function test_an_empty_feed_still_reserves_one_delivery_before_scheduling(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([]);
        foreach ($run->lanes as $lane) {
            $lane->activities()->delete();
        }
        $job = new CallTheRunJob($run->id, $work->id);
        $job->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(0);
        $this->assertNotNull($work->refresh()->claimed_at);
        Queue::assertPushed(CallTheRunJob::class, 1);
        $job->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(0);
        Queue::assertPushed(CallTheRunJob::class, 1);
    }

    public function test_changing_the_sequence_cannot_spend_another_delivery_reference(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([]);
        (new CallTheRunJob($run->id, $work->id, 2))->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(0);
        Queue::assertNotPushed(CallTheRunJob::class);
        $this->assertNull($work->refresh()->claimed_at);
    }

    public function test_revocation_stops_paid_commentary_and_its_chain(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($work->intent_id);
        auth('web')->logout();
        (new CallTheRunJob($run->id, $work->id))->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(0);
        Queue::assertNotPushed(CallTheRunJob::class);
        $this->assertNull($work->refresh()->claimed_at);
    }

    public function test_emergency_stop_stays_available_without_provider_readiness_and_stops_narration(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([]);
        config(['prism.providers.anthropic.api_key' => '']);
        app(BenchmarkFuse::class)->trip($run);
        $this->assertSame('cancelled', $run->refresh()->status);
        (new CallTheRunJob($run->id, $work->id))->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(0);
        Queue::assertNotPushed(CallTheRunJob::class);
    }

    public function test_the_last_approved_delivery_does_not_extend_a_settled_run(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $run->forceFill(['status' => 'completed'])->save();
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned full time.')]);
        (new CallTheRunJob($run->id, $work->id))->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(1);
        Queue::assertNotPushed(CallTheRunJob::class);
    }

    public function test_a_native_narrator_failure_does_not_fail_the_run_or_allow_replay(): void
    {
        [$run, $work] = $this->commentaryFixture();
        $fake = Prism::fake([fn () => throw new \RuntimeException('Owned narrator failure')]);
        $job = new CallTheRunJob($run->id, $work->id);
        $job->handle(app(BenchmarkCommentator::class));
        $this->assertSame('running', $run->refresh()->status);
        $this->assertSame(0, BenchmarkCommentary::query()->count());
        $fake->assertCallCount(1);
        Queue::assertPushed(CallTheRunJob::class, 1);
        $job->handle(app(BenchmarkCommentator::class));
        $fake->assertCallCount(1);
        Queue::assertPushed(CallTheRunJob::class, 1);
    }
}
