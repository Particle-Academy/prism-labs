<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkRunReconciler;
use App\Benchmarks\ProofRecorder;
use App\Http\Controllers\Lab\BenchmarkController;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * What a finished run SHOWS.
 *
 * The Run Room used to display how lanes were going and then, the moment they
 * settled, nothing at all — the proof, its checks, the receipts and the run's
 * own 0Learning were every one of them recorded and none displayed. "PLabs
 * scores only evidence-backed receipts" was a claim the Lab made about itself
 * on a page that never showed a receipt.
 */
class BenchmarkResultsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: BenchmarkRun, 1: BenchmarkLane} */
    private function completedRun(): array
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('results', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $run->forceFill(['status' => 'running'])->save();

        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'running'])->save();

        app(ProofRecorder::class)->complete($lane, [
            'spec_digest' => $spec->refresh()->digest,
            'working_artifact' => 'index.php',
            'checks' => ['basic_arithmetic_correct' => 'hand-traced 2+3*4 = 14'],
            'zero_learning' => 'Unary minus binds outside the power operator.',
        ], [
            ['kind' => 'manual_trace_arithmetic', 'payload' => ['expression' => '2+3*4', 'expected' => 14]],
        ]);

        app(BenchmarkRunReconciler::class)->reconcile($run->refresh());

        return [$run->refresh(), $lane->refresh()];
    }

    private function props($run): array
    {
        $request = Request::create('/lab/benchmarks/runs', 'GET', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '']);

        return app(BenchmarkController::class)->runRoom($run)->toResponse($request)->getData(true)['props'];
    }

    public function test_a_finished_lane_shows_its_artifact_checks_and_receipts(): void
    {
        [$run] = $this->completedRun();

        $results = $this->props($run)['results'];

        $this->assertCount(1, $results);
        $this->assertSame('index.php', $results[0]['working_artifact']);
        $this->assertSame('hand-traced 2+3*4 = 14', $results[0]['checks']['basic_arithmetic_correct']);
        $this->assertCount(1, $results[0]['receipts']);
        $this->assertSame('manual_trace_arithmetic', $results[0]['receipts'][0]['kind']);
        $this->assertNotEmpty($results[0]['receipts'][0]['digest'], 'A receipt without its digest is not independently checkable.');
    }

    public function test_the_lane_reports_its_own_zero_learning(): void
    {
        [$run] = $this->completedRun();

        $this->assertSame(
            'Unary minus binds outside the power operator.',
            $this->props($run)['results'][0]['zero_learning'],
        );
    }

    public function test_an_unscored_lane_says_so_instead_of_showing_zero(): void
    {
        // Nothing in the Lab computes a score yet -- ProofRecorder accepts one
        // and no caller passes it. A "0" on screen would be read as a verdict
        // on the model rather than as a gap in the Lab.
        [$run] = $this->completedRun();

        $result = $this->props($run)['results'][0];

        $this->assertFalse($result['scored']);
        $this->assertNull($result['score']);
    }

    public function test_the_run_shows_the_learning_it_filed(): void
    {
        [$run] = $this->completedRun();

        $learning = $this->props($run)['learning'];

        $this->assertNotNull($learning, 'Every terminal run files a 0L, and the run that filed it must show it.');
        $this->assertSame($run->refresh()->learning_ref, $learning['ref']);
        $this->assertNotEmpty($learning['why_it_matters']);
    }

    public function test_a_lane_with_no_proof_contributes_no_result(): void
    {
        // A failed lane has no Proof-of-Working, and inventing an empty result
        // row for it would put a blank scorecard next to a real one.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('no proof', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $run->lanes()->update(['status' => 'failed', 'finished_at' => now()]);

        $this->assertSame([], $this->props($run->refresh())['results']);
    }
}
