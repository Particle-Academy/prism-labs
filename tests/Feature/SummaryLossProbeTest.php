<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\SummaryLossProbe;
use App\Context\RecordingCompaction;
use App\Jobs\RunSummaryLossProbe;
use App\Models\CompactionProbeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Harness\Context\CompactionOutcome;
use Prism\Harness\Context\KeepRecentTurns;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\TestCase;

/**
 * What can be tested here, and what deliberately cannot.
 *
 * The probe's verdict comes from two live conversations against a real
 * provider, which is the whole reason it exists as a probe rather than as a
 * unit test — a mocked model would answer whatever the mock said and the run
 * would prove nothing about compaction.
 *
 * So what is asserted here is the machinery the verdict RESTS on, and
 * specifically the vacuity guard: that the recorder reports the windows the
 * model was given, and does not report the ones where nothing was compacted. If
 * that is wrong, every verdict the probe reaches is wrong in the direction that
 * looks like a pass.
 */
class SummaryLossProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recorder_returns_the_inner_outcome_untouched(): void
    {
        $messages = [
            new UserMessage('one'),
            new AssistantMessage('two'),
            new UserMessage('three'),
            new AssistantMessage('four'),
        ];

        $inner = new KeepRecentTurns(2);
        $recorder = new RecordingCompaction($inner);

        $expected = $inner->compact($messages);
        $actual = $recorder->compact($messages);

        // A recorder that changed anything would make the probe measure the
        // recorder.
        $this->assertSame(count($expected->kept), count($actual->kept));
        $this->assertSame(count($expected->evicted), count($actual->evicted));
    }

    public function test_it_only_reports_windows_where_something_was_evicted(): void
    {
        $recorder = new RecordingCompaction(new KeepRecentTurns(10));

        // Under the threshold: nothing is evicted, so the window is the whole
        // conversation and contains everything. Counting it would make every
        // run report that the summary kept the fact.
        $recorder->compact([new UserMessage('a planted detail'), new AssistantMessage('ok')]);

        $this->assertSame(0, $recorder->compactions());
        $this->assertSame([], $recorder->windows());
    }

    public function test_it_keeps_every_window_not_just_the_last(): void
    {
        // A summariser rewrites its own summary each turn, so a detail can be
        // dropped on one turn and reintroduced on the next. Checking only the
        // final window would report that as a clean loss.
        $recorder = new RecordingCompaction(new class implements CompactionStrategy
        {
            private int $calls = 0;

            #[\Override]
            public function compact(array $messages): CompactionOutcome
            {
                $this->calls++;

                return new CompactionOutcome(
                    kept: [new UserMessage($this->calls === 1 ? 'the bookkeeper detail' : 'nothing of note')],
                    evicted: [new UserMessage('older')],
                );
            }
        });

        $recorder->compact([new UserMessage('x')]);
        $recorder->compact([new UserMessage('y')]);

        $this->assertSame(2, $recorder->compactions());

        $windows = $recorder->windows();

        $this->assertCount(2, $windows);
        $this->assertStringContainsString('bookkeeper', $windows[0]);
        $this->assertStringNotContainsString('bookkeeper', $windows[1]);
    }

    public function test_it_measures_the_summary_at_every_compaction(): void
    {
        // The budget check rests entirely on reading the summariser's marker
        // back out of the window. If that parse breaks, every run reports a
        // zero-length summary and silently passes the budget gate — which is
        // the gate that caught a stated 15 words coming back as 346.
        $recorder = new RecordingCompaction(new class implements CompactionStrategy
        {
            private int $calls = 0;

            #[\Override]
            public function compact(array $messages): CompactionOutcome
            {
                $this->calls++;

                return new CompactionOutcome(
                    kept: [new UserMessage(SummarisingCompaction::MARKER.' '.implode(' ', array_fill(0, $this->calls * 10, 'word')))],
                    evicted: [new UserMessage('older')],
                );
            }
        });

        $recorder->compact([new UserMessage('x')]);
        $recorder->compact([new UserMessage('y')]);
        $recorder->compact([new UserMessage('z')]);

        $lengths = $this->lengthsFor($recorder);

        // Growing, and read off each compaction rather than only the last.
        $this->assertSame([10, 20, 30], $lengths);
    }

    public function test_a_compaction_with_no_summary_is_not_a_short_one(): void
    {
        $recorder = new RecordingCompaction(new KeepRecentTurns(1));

        $recorder->compact([new UserMessage('a'), new AssistantMessage('b')]);

        // KeepRecentTurns writes no summary. Recording that as 0 words would
        // read as a perfectly compressed summary and pass the budget gate,
        // which is the wrong direction to be wrong in.
        $this->assertSame([-1], $this->lengthsFor($recorder));
    }

    public function test_a_probe_that_dies_still_leaves_a_row(): void
    {
        // The worst outcome — the worker crashing, or the run exceeding its
        // half-hour timeout — is the one that would otherwise leave no trace,
        // and the page would show an older run as though it were current.
        (new RunSummaryLossProbe)->failed(new \RuntimeException('the worker went away'));

        $row = CompactionProbeRun::query()->firstOrFail();

        $this->assertSame('summary-loss', $row->probe);
        $this->assertSame('error', $row->verdict);
        $this->assertStringContainsString('the worker went away', (string) $row->failure);

        // And an error row must not read as a clean run: nothing was compacted,
        // nothing was looked up, nothing was recovered.
        $this->assertFalse($row->fact_left_window);
        $this->assertFalse($row->looked);
        $this->assertFalse($row->correct);
        $this->assertFalse($row->confabulated);
    }

    /**
     * Reached by reflection, deliberately.
     *
     * The alternative is making the measurement public purely so a test can
     * see it, which would put a method on the probe's surface that nothing in
     * the application calls. The parse is worth pinning; the API is not worth
     * widening for it.
     *
     * @return list<int>
     */
    private function lengthsFor(RecordingCompaction $recorder): array
    {
        $method = new \ReflectionMethod(SummaryLossProbe::class, 'summaryLengths');

        return $method->invoke(new SummaryLossProbe, $recorder);
    }
}
