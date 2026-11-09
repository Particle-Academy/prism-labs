<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkScorer;
use App\Jobs\ScoreLaneJob;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

final class BenchmarkScoringAuthorityTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
        Queue::fake();
    }

    private function scoreFixture(): array
    {
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        $work = $this->boundBenchmarkScore($lane);
        $this->beginBenchmarkJob('scoring');

        return [$lane, $work];
    }

    private function response(): TextResponseFake
    {
        return TextResponseFake::make()->withText('{"dimensions":[{"name":"quality","score":80,"justification":"Owned check","cited_receipt":"owned-check"}]}');
    }

    public function test_a_direct_scorer_call_without_its_work_context_never_reaches_the_sdk(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkScorer::class)->score($lane);
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull($work->refresh()->claimed_at);
        }
    }

    public function test_a_legacy_scoring_job_with_no_reference_refuses_without_inference(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([]);
        $job = (new ScoreLaneJob($lane->id))->withFakeQueueInteractions();
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertCallCount(0);
        $this->assertNull($work->refresh()->claimed_at);
        $this->assertStringContainsString('No inference was started', $lane->activities()->reorder('id', 'desc')->value('summary'));
    }

    public function test_bound_scoring_works_without_a_web_login_and_never_runs_twice(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([$this->response()]);
        $this->assertGuest('web');
        $job = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertCallCount(1);
        $this->assertEqualsWithDelta(80, (float) $lane->refresh()->score, .001);
        $this->assertNotNull($work->refresh()->claimed_at);
        $job->handle(app(BenchmarkScorer::class));
        $fake->assertCallCount(1);
    }

    public function test_free_listing_retries_leave_the_paid_claim_unused_until_recovery(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([$this->response()]);
        Cache::put('lab.model-listing.v1.anthropic', ['status' => 'unreachable', 'models' => [],
            'checked_at' => now()->toIso8601String(), 'message' => 'Owned fixture unavailable'], 60);
        $first = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $first->handle(app(BenchmarkScorer::class));
        $first->assertReleased(120);
        $fake->assertCallCount(0);
        $this->assertNull($work->refresh()->claimed_at);
        $this->cacheModelRoster();
        $recovered = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $recovered->handle(app(BenchmarkScorer::class));
        $recovered->assertNotReleased();
        $fake->assertCallCount(1);
        $this->assertNotNull($work->refresh()->claimed_at);
    }

    public function test_listing_exhaustion_does_not_claim_or_retry_inference(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([]);
        Cache::put('lab.model-listing.v1.anthropic', ['status' => 'unreachable', 'models' => [],
            'checked_at' => now()->toIso8601String(), 'message' => 'Owned fixture unavailable'], 60);
        $job = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $job->listingRetryDeadline = now()->timestamp;
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertCallCount(0);
        $this->assertNull($work->refresh()->claimed_at);
        $this->assertStringContainsString("couldn't reach the provider's model list", $lane->activities()->reorder('id', 'desc')->value('summary'));
    }

    public function test_native_inference_failure_remains_uncertain_and_is_never_replayed(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([fn () => throw new \RuntimeException('Owned provider failure')]);
        $job = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertCallCount(1);
        $this->assertNotNull($work->refresh()->claimed_at);
        $this->assertNull($lane->refresh()->score);
        $again = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $again->handle(app(BenchmarkScorer::class));
        $again->assertNotReleased();
        $fake->assertCallCount(1);
        $this->assertStringContainsString('uncertain', $lane->activities()->reorder('id', 'desc')->value('summary'));
    }

    #[DataProvider('changedEvidence')]
    public function test_changed_actual_proof_or_receipts_refuse_before_inference(string $change): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([]);
        if ($change === 'proof') {
            $lane->forceFill(['proof' => [...$lane->proof, 'working_artifact' => 'unapproved.txt']])->save();
        } else {
            $lane->receipts()->firstOrFail()->forceFill([$change => match ($change) {
                'digest' => str_repeat('f', 64), 'created_at' => now()->subHour(), default => ['changed' => true],
            }])->save();
        }
        $job = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertCallCount(0);
        $this->assertNull($work->refresh()->claimed_at);
    }

    public static function changedEvidence(): array
    {
        return [['proof'], ['payload'], ['digest'], ['created_at']];
    }

    public function test_revoked_authority_cannot_judge_a_queued_submission(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($work->intent_id);
        auth('web')->logout();
        (new ScoreLaneJob($lane->id, $work->id))->handle(app(BenchmarkScorer::class));
        $fake->assertCallCount(0);
        $this->assertNull($work->refresh()->claimed_at);
    }

    public function test_a_root_http_context_cannot_stand_in_for_score_work(): void
    {
        [$lane, $work] = $this->scoreFixture();
        $fake = Prism::fake([]);
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 1800)));
        $scope = app(RunScopeFactory::class)->fromAction('benchmarks.launch', $lane->benchmarkRun->benchmark_spec_id, []);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(PaidRunContext::class)->within($work->intent_id, $scope, fn () => app(BenchmarkScorer::class)->score($lane));
        } finally {
            $fake->assertCallCount(0);
        }
    }

    public function test_receipt_drift_during_real_free_model_discovery_refuses_before_provider_inference(): void
    {
        [$lane, $work] = $this->scoreFixture();
        Cache::forget('lab.model-listing.v1.anthropic');
        $listed = 0;
        Http::fake(['https://api.anthropic.com/*' => function ($request) use ($lane, &$listed) {
            if ($request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/models')) {
                $listed++;
                $lane->receipts()->firstOrFail()->forceFill(['payload' => ['changed_while_listing' => true]])->save();

                return Http::response(['data' => [['id' => 'claude-sonnet-5', 'type' => 'model', 'display_name' => 'Owned Sonnet',
                    'created_at' => '2026-11-01T00:00:00Z']], 'has_more' => false]);
            }

            return Http::response(['error' => 'Unexpected inference in owned fixture'], 500);
        }]);
        $job = (new ScoreLaneJob($lane->id, $work->id))->withFakeQueueInteractions();
        $job->handle(app(BenchmarkScorer::class));
        $this->assertSame(1, $listed, 'The real provider discovery must have happened before the drift refusal.');
        $this->assertSame('ready', Cache::get('lab.model-listing.v1.anthropic')['status']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertNull($work->refresh()->claimed_at);
        $this->assertStringContainsString('No inference was started', $lane->activities()->reorder('id', 'desc')->value('summary'));
    }
}
