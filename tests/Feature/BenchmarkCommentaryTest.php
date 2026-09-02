<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkCommentator;
use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\LaneActivity;
use App\Http\Controllers\Lab\BenchmarkController;
use App\Jobs\CallTheRunJob;
use App\Models\BenchmarkCommentary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

/**
 * The overseer calling a run.
 *
 * The design is shaped by two hard constraints, and both are pinned here
 * because breaking either brings the Lab down rather than degrading it: the
 * commentary is model-generated, so it must never run inside a page request
 * (one FastCGI worker serves the whole site), and it must never run on the
 * `default` queue (the lane occupies that for the length of the run).
 */
class BenchmarkCommentaryTest extends TestCase
{
    use RefreshDatabase;

    private function liveRun()
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('commentary', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
        ]], ['cost' => 1]);

        return $designer->launch($designer->approve($designer->requestApproval($spec)));
    }

    public function test_the_page_dispatches_commentary_and_never_generates_it_in_the_request(): void
    {
        // A model call inside a page request stalls the Lab's only FastCGI
        // worker until Caddy gives up and answers 502. The request may do no
        // more than dispatch.
        Queue::fake();
        $run = $this->liveRun();
        $run->forceFill(['status' => 'running'])->save();

        app(BenchmarkController::class)->runRoom($run->refresh());

        Queue::assertPushed(CallTheRunJob::class);
    }

    public function test_commentary_runs_on_its_own_queue_not_behind_the_lane(): void
    {
        // `default` is occupied by the lane for the whole run, so commentary
        // queued there would arrive after the thing it narrates had finished.
        $this->assertSame('commentary', (new CallTheRunJob('any'))->queue);
    }

    public function test_the_dispatch_is_throttled_so_several_viewers_do_not_pay_several_times(): void
    {
        Queue::fake();
        $run = $this->liveRun();
        $run->forceFill(['status' => 'running'])->save();

        app(BenchmarkController::class)->runRoom($run->refresh());
        app(BenchmarkController::class)->runRoom($run->refresh());
        app(BenchmarkController::class)->runRoom($run->refresh());

        Queue::assertPushed(CallTheRunJob::class, 1);
    }

    public function test_a_settled_run_is_not_narrated_any_further(): void
    {
        Queue::fake();
        $run = $this->liveRun();
        $run->forceFill(['status' => 'completed'])->save();

        app(BenchmarkController::class)->runRoom($run->refresh());

        Queue::assertNotPushed(CallTheRunJob::class);
    }

    public function test_it_calls_only_the_events_it_has_not_called_yet(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Sonnet 5 is straight into the workspace.'),
            TextResponseFake::make()->withText('Still nothing from lane 2.'),
        ]);
        $run = $this->liveRun();
        $lane = $run->lanes()->firstOrFail();
        $activity = app(LaneActivity::class);
        $activity->record($lane, 'lane.started', 'first');
        $activity->record($lane, 'file.written', 'second');

        $commentator = app(BenchmarkCommentator::class);
        $first = $commentator->call($run->refresh());

        $this->assertNotNull($first);
        $this->assertSame('Sonnet 5 is straight into the workspace.', $first->line);

        // Nothing new has happened, so there is nothing to say — and saying
        // something anyway would mean paying a model to repeat itself.
        $this->assertNull($commentator->call($run->refresh()));

        $activity->record($lane, 'file.written', 'third');
        $second = $commentator->call($run->refresh());

        $this->assertNotNull($second);
        $this->assertGreaterThan($first->after_activity_id, $second->after_activity_id);
    }

    public function test_it_calls_tool_activity_even_when_no_milestone_has_landed(): void
    {
        // The silence bug, pinned. An agent deep in a build emits tool calls
        // into `lab_operations` for minutes without writing a single row to
        // `benchmark_lane_activities` — so a commentator reading only the
        // second went quiet through exactly the stretch worth calling. The
        // lane heartbeat had already made this mistake once.
        Prism::fake([TextResponseFake::make()->withText('Sonnet 5 is writing files fast.')]);
        $run = $this->liveRun();
        $lane = $run->lanes()->firstOrFail();

        DB::table('lab_operations')->insert([
            'id' => (string) Str::uuid(), 'benchmark_lane_id' => $lane->id, 'kind' => 'tool.call',
            'status' => 'completed', 'metadata' => json_encode(['tool_name' => 'workspace_write']),
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $line = app(BenchmarkCommentator::class)->call($run->refresh());

        $this->assertNotNull($line, 'Tool calls alone must be enough to say something.');
        $this->assertNotNull($line->after_operation_at, 'The operation cursor must advance, or the same call repeats forever.');
    }

    public function test_a_failing_overseer_never_takes_the_run_with_it(): void
    {
        // Commentary is decoration. A run that fails because its narrator did
        // is an absurdity.
        Prism::fake([fn () => throw new \RuntimeException('provider down')]);
        $run = $this->liveRun();
        app(LaneActivity::class)->record($run->lanes()->firstOrFail(), 'lane.started', 'first');

        $this->assertNull(app(BenchmarkCommentator::class)->call($run->refresh()));
        $this->assertSame(0, BenchmarkCommentary::query()->count());
    }

    public function test_a_line_that_ignores_the_brief_is_bounded_before_it_reaches_the_ticker(): void
    {
        // A ticker line that scrolls for a minute is not a ticker line.
        Prism::fake([TextResponseFake::make()->withText(str_repeat('and then ', 200))]);
        $run = $this->liveRun();
        app(LaneActivity::class)->record($run->lanes()->firstOrFail(), 'lane.started', 'first');

        $line = app(BenchmarkCommentator::class)->call($run->refresh());

        $this->assertNotNull($line);
        $this->assertLessThanOrEqual(400, mb_strlen($line->line));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
    }
}
