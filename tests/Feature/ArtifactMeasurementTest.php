<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\LaneWorkspace;
use App\Models\BenchmarkLane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artifact is measured off disk, not taken from the agent's word for it.
 *
 * `PROOF_OF_WORKING.json` is written by the agent under test. Until this
 * existed, every field in it was trusted — including `working_artifact`, which
 * the scorer handed the judge as `SUBMITTED ARTIFACT: <path>`. A NAME. Nothing
 * opened the file or checked it existed, so an agent that wrote a convincing
 * proof document beside a thin or absent artifact scored exactly as well as one
 * that did the work.
 *
 * The `flabs` team found the same hole in their own lab first and named it
 * precisely: they shipped two document writers that accepted a rich schema and
 * emitted a plain document, and a reviewer reading the REQUEST would have passed
 * both. These tests are their rule applied here — judge from evidence read out
 * of the artifact, never from what the agent submitted.
 */
class ArtifactMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private function lane(): BenchmarkLane
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('artifact', 'application', 'standard',
            ['outcome' => 'Build something.', 'acceptance_criteria' => ['it exists']],
            ['dimensions' => [['name' => 'Correctness', 'weight' => 1.0, 'criteria' => 'it works']]],
            [['language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5']],
            ['steps' => 8],
        );
        $designer->approve($designer->requestApproval($spec));
        $run = $designer->launch($spec);

        return BenchmarkLane::query()->where('benchmark_run_id', $run->id)->firstOrFail();
    }

    public function test_it_measures_an_artifact_that_is_really_there(): void
    {
        $lane = $this->lane();
        $workspaces = app(LaneWorkspace::class);
        $workspaces->workspace($lane)->write('report.txt', 'the actual work');

        $measured = $workspaces->measure($lane, 'report.txt');

        // Facts read off disk, not copied from the proof document.
        $this->assertTrue($measured['exists']);
        $this->assertSame(strlen('the actual work'), $measured['size']);
        $this->assertSame(hash('sha256', 'the actual work'), $measured['sha256']);
    }

    public function test_it_reports_an_artifact_that_was_never_written(): void
    {
        // The claim a benchmark must never accept: "I built it", with nothing on
        // disk. The executor turns this into a failed lane rather than a scored
        // one.
        $measured = app(LaneWorkspace::class)->measure($this->lane(), 'promised.xlsx');

        $this->assertFalse($measured['exists']);
        $this->assertNull($measured['size']);
        $this->assertNull($measured['sha256']);
    }

    public function test_it_reports_an_empty_artifact_path_as_absent(): void
    {
        // A proof document with `"working_artifact": ""` must not resolve to the
        // workspace root and look like a hit.
        $measured = app(LaneWorkspace::class)->measure($this->lane(), '');

        $this->assertFalse($measured['exists']);
    }

    public function test_the_digest_distinguishes_two_files_of_the_same_size(): void
    {
        // The vacuity guard. A measurement that only counted bytes would pass
        // the two tests above while being unable to tell a real document from a
        // same-length placeholder, which is the substitution this exists to
        // make visible.
        $lane = $this->lane();
        $workspaces = app(LaneWorkspace::class);
        $workspaces->workspace($lane)->write('real.txt', 'AAAAAAAAAA');
        $workspaces->workspace($lane)->write('fake.txt', 'BBBBBBBBBB');

        $real = $workspaces->measure($lane, 'real.txt');
        $fake = $workspaces->measure($lane, 'fake.txt');

        $this->assertSame($real['size'], $fake['size']);
        $this->assertNotSame($real['sha256'], $fake['sha256']);
    }

    public function test_it_digests_a_binary_artifact_without_reading_it_whole(): void
    {
        // Document benchmarks produce binaries, and the digest streams. This
        // pins that a file with null bytes measures correctly — the code viewer
        // deliberately refuses those, and measurement must not inherit that
        // restriction.
        $lane = $this->lane();
        $workspaces = app(LaneWorkspace::class);
        $bytes = random_bytes(400_000);
        $workspaces->workspace($lane)->write('deck.pptx', $bytes);

        $measured = $workspaces->measure($lane, 'deck.pptx');

        $this->assertTrue($measured['exists']);
        $this->assertSame(400_000, $measured['size']);
        $this->assertSame(hash('sha256', $bytes), $measured['sha256']);
    }
}
