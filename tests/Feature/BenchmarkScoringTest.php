<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkScorer;
use App\Benchmarks\ProofRecorder;
use App\Benchmarks\Rubric;
use App\Jobs\ScoreLaneJob;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkScore;
use App\Models\BenchmarkSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

/**
 * Scoring a lane against its frozen rubric.
 *
 * The judge is blind and narrowed, and both properties are load-bearing rather
 * than decorative — see the individual tests for why each was bought.
 */
class BenchmarkScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
    }

    private function scoredLane(string $judgeJson): BenchmarkLane
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

        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'running'])->save();

        app(ProofRecorder::class)->complete($lane, [
            'spec_digest' => $spec->refresh()->digest,
            'working_artifact' => 'index.php',
            'checks' => ['arithmetic' => 'hand-traced 2+3*4 = 14'],
            'zero_learning' => 'Unary minus binds outside the power operator.',
        ], [
            ['kind' => 'manual_trace_arithmetic', 'payload' => ['expression' => '2+3*4', 'expected' => 14]],
        ]);

        Prism::fake([TextResponseFake::make()->withText($judgeJson)]);
        app(BenchmarkScorer::class)->score($lane->refresh()->load(['receipts', 'benchmarkRun.spec']));

        return $lane->refresh();
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
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'running'])->save();
        app(ProofRecorder::class)->complete($lane, [
            'spec_digest' => $spec->refresh()->digest, 'working_artifact' => 'a.php',
            'checks' => ['x' => 'y'], 'zero_learning' => 'z',
        ], [['kind' => 'k', 'payload' => ['a' => 1]]]);

        app(BenchmarkScorer::class)->score($lane->refresh()->load(['receipts', 'benchmarkRun.spec']));

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
