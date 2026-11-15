<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkScorer;
use App\Benchmarks\BenchmarkWork;
use App\Benchmarks\LaneActivity;
use App\Benchmarks\Rubric;
use App\Http\Controllers\Lab\BenchmarkController;
use App\Jobs\ScoreLaneJob;
use App\Lab\ModelPolicy;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunScopeFactory;
use App\Lab\SessionPreflight;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkScore;
use App\Models\BenchmarkSpec;
use App\Models\LabRunIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

/**
 * Scoring a lane against its frozen rubric.
 *
 * The judge is blind and narrowed, and both properties are load-bearing rather
 * than decorative — see the individual tests for why each was bought.
 */
#[Group('dogfood')]
class BenchmarkScoringTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $scoreWork = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
        $this->cacheModelRoster();
        app(ModelPolicy::class)->allow(['anthropic:claude-sonnet-5']);
        Queue::fake();
    }

    private function scoredLane(string $judgeJson, bool $score = true): BenchmarkLane
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('scoring', 'application', 'standard', [
            'outcome' => 'Build a calculator.', 'acceptance_criteria' => ['arithmetic is correct'],
        ], ['dimensions' => [
            ['name' => 'Correctness', 'weight' => 0.75, 'criteria' => 'arithmetic is right'],
            ['name' => 'Code Quality', 'weight' => 0.25, 'criteria' => 'readable'],
        ]], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
        ]], ['cost' => 1]);

        $run = $this->dispatchApprovedDraft($spec);
        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'running'])->save();

        $work = $this->submitBenchmarkProof($lane, [
            'spec_digest' => $spec->refresh()->digest,
            'working_artifact' => 'index.php',
            'checks' => ['arithmetic' => 'hand-traced 2+3*4 = 14'],
            'zero_learning' => 'Unary minus binds outside the power operator.',
        ], [
            ['kind' => 'manual_trace_arithmetic', 'payload' => ['expression' => '2+3*4', 'expected' => 14]],
        ]);

        $this->scoreWork[$lane->id] = $work->id;
        if ($score) {
            Prism::fake([TextResponseFake::make()->withText($judgeJson)]);
            $this->scoreBoundLane($lane);
        }

        return $lane->refresh();
    }

    private function scoreBoundLane(BenchmarkLane $lane): void
    {
        $this->beginBenchmarkJob('scoring');
        app(PaidWorkRegistry::class)->run($this->scoreWork[$lane->id], 'benchmark.score', $lane->id,
            BenchmarkWork::scorePayload($lane), fn () => app(BenchmarkScorer::class)->score($lane->refresh()->load(['receipts', 'benchmarkRun.spec'])));
    }

    private function scoringJob(BenchmarkLane $lane): ScoreLaneJob
    {
        $this->beginBenchmarkJob('scoring');

        return (new ScoreLaneJob($lane->id, $this->scoreWork[$lane->id]))->withFakeQueueInteractions();
    }

    public function test_the_judge_is_never_told_which_model_built_the_submission(): void
    {
        // THE property. `BenchmarkDesigner::launch()` shuffles the lanes so that
        // nothing downstream can be biased by the order the spec listed them in.
        // A judge shown "claude-sonnet-5" would hand back exactly the bias that
        // shuffle was bought to prevent, and the benchmark would be measuring
        // reputation rather than work.
        $fake = Prism::fake([TextResponseFake::make()->withText('{"dimensions":[{"name":"Correctness","score":80,"justification":"traced","cited_receipt":"manual_trace_arithmetic"}]}')]);

        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('blind', 'application', 'standard', ['outcome' => 'x'], ['dimensions' => [
            ['name' => 'Correctness', 'weight' => 1.0, 'criteria' => 'right'],
        ]], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
        ]], ['cost' => 1]);
        $run = $this->dispatchApprovedDraft($spec);
        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'running'])->save();
        $work = $this->submitBenchmarkProof($lane, [
            'spec_digest' => $spec->refresh()->digest, 'working_artifact' => 'a.php',
            'checks' => ['x' => 'y'], 'zero_learning' => 'z',
        ], [['kind' => 'k', 'payload' => ['a' => 1]]]);

        $this->scoreWork[$lane->id] = $work->id;
        $this->scoreBoundLane($lane);

        $fake->assertRequest(function (array $requests): void {
            $sent = (string) json_encode($requests[0]->messages());

            // Positive control FIRST. A "does not contain" assertion against an
            // empty haystack passes for the wrong reason, and a security
            // property that is only ever asserted negatively is a claim rather
            // than a check — the exact failure this workspace was built to
            // refuse. So prove the prompt is really in there before proving
            // what is absent from it.
            $this->assertStringContainsString('a.php', $sent, 'The prompt must actually be here, or the blindness assertions below are vacuous.');
            $this->assertStringContainsString('Correctness', $sent, 'The rubric must reach the judge.');

            $this->assertStringNotContainsString('claude-sonnet-5', $sent, 'The judge must not learn which model built this.');
            $this->assertStringNotContainsString('anthropic', $sent, 'Nor which provider.');
        });
    }

    public function test_the_judge_has_no_tools(): void
    {
        // Same argument the Lab already makes for its verifier: a judge able to
        // reach the workspace it is scoring could look past the receipts at the
        // artifact itself, and the claim of this surface is that a score rests
        // on evidence the builder submitted and anyone can re-check.
        $this->assertSame([], config('prism-harness.agent.modes.scoring.tools'));
    }

    public function test_it_records_a_weighted_total_and_the_dimensions_behind_it(): void
    {
        $lane = $this->scoredLane('{"dimensions":[
            {"name":"Correctness","score":80,"justification":"trace checks out","cited_receipt":"manual_trace_arithmetic"},
            {"name":"Code Quality","score":40,"justification":"no receipt for this","cited_receipt":null}
        ]}');

        // 80 * 0.75 + 40 * 0.25 = 70
        $this->assertEqualsWithDelta(70.0, (float) $lane->score, 0.01);
        $this->assertSame(2, BenchmarkScore::query()->where('benchmark_lane_id', $lane->id)->count());
    }

    public function test_a_dimension_with_no_receipt_is_recorded_as_uncited(): void
    {
        // A judgement that names no evidence is an opinion. It is kept, because
        // "nothing supported this" is a finding worth reading — but it is
        // marked, so nobody mistakes it for an evidenced score.
        $lane = $this->scoredLane('{"dimensions":[
            {"name":"Correctness","score":80,"justification":"trace","cited_receipt":"manual_trace_arithmetic"},
            {"name":"Code Quality","score":40,"justification":"nothing showed me the code","cited_receipt":null}
        ]}');

        $quality = BenchmarkScore::query()->where('benchmark_lane_id', $lane->id)->where('dimension', 'Code Quality')->firstOrFail();

        $this->assertNull($quality->cited_receipt);
        $this->assertSame('manual_trace_arithmetic', BenchmarkScore::query()->where('dimension', 'Correctness')->firstOrFail()->cited_receipt);
    }

    public function test_a_dimension_the_rubric_does_not_have_is_discarded(): void
    {
        // A judge inventing a dimension would silently reweight the rubric it
        // was asked to apply.
        $lane = $this->scoredLane('{"dimensions":[
            {"name":"Correctness","score":100,"justification":"ok","cited_receipt":"manual_trace_arithmetic"},
            {"name":"Vibes","score":10,"justification":"made this up","cited_receipt":null}
        ]}');

        $this->assertSame(0, BenchmarkScore::query()->where('dimension', 'Vibes')->count());
        $this->assertEqualsWithDelta(100.0, (float) $lane->score, 0.01, 'The total must re-base over the dimensions actually scored.');
    }

    public function test_a_judge_that_returns_junk_leaves_the_lane_unscored_rather_than_wrong(): void
    {
        $lane = $this->scoredLane('I am afraid I cannot do that.');

        $this->assertNull($lane->score);
        $this->assertSame(0, BenchmarkScore::query()->count());
    }

    public function test_scoring_runs_on_its_own_queue(): void
    {
        // `default` carries the lanes for the length of a run, so a scoring job
        // queued there would wait behind every remaining lane.
        $this->assertSame('scoring', (new ScoreLaneJob('any'))->queue);
    }

    private function pendingLane(): BenchmarkLane
    {
        return $this->scoredLane('', score: false);
    }

    private function unreachableListing(): void
    {
        Cache::put('lab.model-listing.v1.anthropic', ['status' => 'unreachable', 'models' => [], 'checked_at' => now()->toIso8601String(), 'message' => 'Fixture listing unavailable.'], 60);
    }

    public function test_real_authentication_401_does_not_release_the_scoring_job(): void
    {
        $this->assertAuthenticationDoesNotRetry(401);
    }

    public function test_real_authentication_403_does_not_release_the_scoring_job(): void
    {
        $this->assertAuthenticationDoesNotRetry(403);
    }

    private function assertAuthenticationDoesNotRetry(int $status): void
    {
        $lane = $this->pendingLane();
        $proof = $lane->proof;
        app(ModelPolicy::class)->allow(['anthropic:claude-sonnet-5']);
        $this->app->instance(PrismManager::class, new PrismManager($this->app));
        config(['prism.providers.anthropic.url' => 'https://authentication.fixture/v1']);
        Cache::forget('lab.model-listing.v1.anthropic');
        Http::preventStrayRequests();
        $requests = [];
        Http::fake(['https://authentication.fixture/*' => function (Request $request) use ($status, &$requests) {
            $requests[] = ['method' => $request->method(), 'path' => parse_url($request->url(), PHP_URL_PATH)];

            return Http::response(['error' => 'fixture-private-auth-body fixture-only'], $status);
        }]);
        $job = $this->scoringJob($lane);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $reason = 'Not scored: the provider rejected a selected API key or its access. Update the Lab configuration before retrying.';
        $this->assertSame($reason, $lane->activities()->reorder('id', 'desc')->value('summary'));
        $this->assertSame([['method' => 'GET', 'path' => '/v1/models']], $requests, 'Only one model listing; never inference or auth retries.');
        $this->assertNull($lane->refresh()->score);
        $this->assertSame($proof, $lane->proof);
        $this->assertSame(0, $lane->activities()->where('kind', 'scoring.started')->count());
        Route::middleware('web')->get('/fixtures/scoring-auth/{run}', [BenchmarkController::class, 'runRoom']);
        $response = $this->get('/fixtures/scoring-auth/'.$lane->benchmark_run_id);
        $response->assertOk()->assertInertia(fn ($page) => $page->where('results.0.scoring_reason', $reason));
        $this->assertStringNotContainsString('fixture-private-auth-body', $response->getContent());
        $this->assertStringNotContainsString('authentication.fixture', $response->getContent());
    }

    public function test_real_scoring_job_defers_before_inference_then_scores_after_listing_recovery(): void
    {
        $lane = $this->pendingLane();
        $proof = $lane->proof;
        $this->unreachableListing();
        $fake = Prism::fake([]);
        $job = $this->scoringJob($lane);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertReleased(120);
        $fake->assertRequest(fn (array $requests) => $this->assertCount(0, $requests));
        $this->assertStringContainsString('deferred', $lane->activities()->reorder('id', 'desc')->value('summary'));
        $this->assertSame($proof, $lane->refresh()->proof);
        $this->assertNull($lane->score);
        $this->cacheModelRoster();
        $fake = Prism::fake([TextResponseFake::make()->withText('{"dimensions":[{"name":"Correctness","score":80,"justification":"traced"}]}')]);
        $recovered = $this->scoringJob($lane);
        $recovered->handle(app(BenchmarkScorer::class));
        $recovered->assertNotReleased();
        $this->assertEquals(80, $lane->refresh()->score);
        $fake->assertRequest(fn (array $requests) => $this->assertCount(1, $requests));
        $again = $this->scoringJob($lane);
        $again->handle(app(BenchmarkScorer::class));
        $fake->assertRequest(fn (array $requests) => $this->assertCount(1, $requests));
    }

    public function test_real_scoring_job_exhaustion_keeps_a_visible_reason_without_inference(): void
    {
        $lane = $this->pendingLane();
        $job = $this->scoringJob($lane);
        // Exhaust the listing window without also expiring its distinct 15-minute GO.
        $job->listingRetryDeadline = now()->timestamp;
        $this->unreachableListing();
        $fake = Prism::fake([]);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertRequest(fn (array $requests) => $this->assertCount(0, $requests));
        $this->assertSame("Not scored: couldn't reach the provider's model list.", $lane->activities()->reorder('id', 'desc')->value('summary'));
        $this->assertNull($lane->refresh()->score);
        Route::middleware('web')->get('/fixtures/scoring/{run}', [BenchmarkController::class, 'runRoom']);
        $this->get('/fixtures/scoring/'.$lane->benchmark_run_id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Lab/RunRoom')->where('results.0.scoring_reason', "Not scored: couldn't reach the provider's model list."));
    }

    public function test_real_scoring_job_does_not_retry_an_operator_choice_or_missing_key(): void
    {
        // Keep application readiness independent of the judging key under test.
        // Freeze this coordinator choice before drafting and consuming the GO.
        config(['team.coordinator.provider' => 'openai', 'prism.providers.openai.api_key' => 'synthetic-readiness-only']);
        $this->cacheModelRoster(includeOpenAI: true);
        app(ModelPolicy::class)->allow(['anthropic:claude-sonnet-5']);
        $lane = $this->pendingLane();
        $this->cacheModelRoster(includeOpenAI: true);
        app(ModelPolicy::class)->allow([]);
        $fake = Prism::fake([]);
        $job = $this->scoringJob($lane);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        // Removing an approved builder model invalidates the run before judge selection.
        $this->assertSame('Not scored: approved run authority or current session readiness is unavailable. No inference was started.', $lane->activities()->reorder('id', 'desc')->value('summary'));
        $this->assertDatabaseHas('lab_paid_works', ['id' => $this->scoreWork[$lane->id], 'claimed_at' => null]);
        app(ModelPolicy::class)->allow(['anthropic:claude-sonnet-5']);
        config(['prism.providers.anthropic.api_key' => '']);
        $missingKey = $this->scoringJob($lane);
        app(SessionPreflight::class)->assertReadyForExecution();
        $intent = LabRunIntent::query()->findOrFail($lane->benchmarkRun->approved_run_id);
        $this->assertSame($intent->payload, app(RunScopeFactory::class)->fromAction('benchmarks.launch', $intent->resource, [])->payload,
            'The missing judge key must not replace the approved model selection or runtime.');
        $missingKey->handle(app(BenchmarkScorer::class));
        $missingKey->assertNotReleased();
        $this->assertStringContainsString('no configured API key', $lane->activities()->reorder('id', 'desc')->value('summary'));
        $fake->assertRequest(fn (array $requests) => $this->assertCount(0, $requests));
    }

    public function test_real_scoring_job_never_retries_after_a_model_call_was_sent(): void
    {
        $lane = $this->pendingLane();
        $fake = Prism::fake([TextResponseFake::make()->withText('not scoring JSON')]);
        $job = $this->scoringJob($lane);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $this->assertStringContainsString('No automatic inference retry', $lane->activities()->reorder('id', 'desc')->value('summary'));
        $this->assertSame(1, $job->maxExceptions, 'A worker exception after a send must fail instead of repeating inference.');
        $fake->assertRequest(fn (array $requests) => $this->assertCount(1, $requests));
        $redelivered = $this->scoringJob($lane);
        $redelivered->handle(app(BenchmarkScorer::class));
        $redelivered->assertNotReleased();
        $fake->assertRequest(fn (array $requests) => $this->assertCount(1, $requests));
        $this->assertStringContainsString('uncertain', $lane->activities()->reorder('id', 'desc')->value('summary'));
    }

    public function test_changed_unsupported_or_unpriced_selections_invalidate_the_approved_work_before_inference(): void
    {
        $lane = $this->pendingLane();
        DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'], ['value' => json_encode(['perplexity:sonar'])]);
        config(['prism.providers.perplexity' => ['api_key' => 'fixture-only', 'url' => 'https://refused.fixture']]);
        $fake = Prism::fake([]);
        $job = $this->scoringJob($lane);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        // These replacements remove the model in the frozen lane matrix. They
        // must refuse at authority validation, before model-listing diagnostics.
        $reason = 'Not scored: approved run authority or current session readiness is unavailable. No inference was started.';
        $this->assertSame($reason, $lane->activities()->reorder('id', 'desc')->value('summary'));
        DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->update(['value' => json_encode(['anthropic:unpriced-future'])]);
        $unpriced = $this->scoringJob($lane);
        $unpriced->handle(app(BenchmarkScorer::class));
        $unpriced->assertNotReleased();
        $this->assertSame($reason, $lane->activities()->reorder('id', 'desc')->value('summary'));
        $this->assertDatabaseHas('lab_paid_works', ['id' => $this->scoreWork[$lane->id], 'claimed_at' => null]);
        $this->assertSame(0, $lane->activities()->where('kind', 'scoring.started')->count());
        $fake->assertRequest(fn (array $requests) => $this->assertCount(0, $requests));
    }

    public function test_worker_redelivery_after_uncertain_inference_does_not_send_again(): void
    {
        $lane = $this->pendingLane();
        app(LaneActivity::class)->record($lane, 'scoring.started', 'Judging delivery started; completion is not known.');
        $fake = Prism::fake([TextResponseFake::make()->withText('{"dimensions":[{"name":"Correctness","score":80,"justification":"traced"}]}')]);
        $job = $this->scoringJob($lane);
        $job->handle(app(BenchmarkScorer::class));
        $job->assertNotReleased();
        $fake->assertRequest(fn (array $requests) => $this->assertCount(0, $requests));
        $this->assertNull($lane->refresh()->score);
        $this->assertStringContainsString('uncertain', $lane->activities()->reorder('id', 'desc')->value('summary'));
    }

    public function test_both_rubric_shapes_normalise_to_weights_that_sum_to_one(): void
    {
        // Two shapes are already frozen into approved specs and a frozen spec
        // cannot be edited, only superseded — so both must keep working.
        $flat = new BenchmarkSpec;
        $flat->rubric = ['functional' => 40, 'quality' => 10];

        $listed = new BenchmarkSpec;
        $listed->rubric = ['dimensions' => [
            ['name' => 'A', 'weight' => 0.25, 'criteria' => 'a'],
            ['name' => 'B', 'weight' => 0.75, 'criteria' => 'b'],
        ]];

        $this->assertEqualsWithDelta(0.8, Rubric::fromSpec($flat)->weightFor('functional'), 0.001);
        $this->assertEqualsWithDelta(0.75, Rubric::fromSpec($listed)->weightFor('B'), 0.001);
    }

    public function test_a_rubric_with_no_usable_weights_scores_every_dimension_equally(): void
    {
        // Refusing would mean a badly written rubric silently produces no score
        // at all, which is the gap this whole surface exists to close.
        $spec = new BenchmarkSpec;
        $spec->rubric = ['dimensions' => [['name' => 'A', 'criteria' => 'a'], ['name' => 'B', 'criteria' => 'b']]];

        $this->assertEqualsWithDelta(0.5, Rubric::fromSpec($spec)->weightFor('A'), 0.001);
    }
}
