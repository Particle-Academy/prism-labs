<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\TableContextRecall;
use App\Context\TableEvictionSink;
use Prism\Harness\Context\KeepRecentTurns;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;
use Tests\TestCase;

/**
 * The Lab's own agent runs inside a bounded window, with recovery.
 *
 * It did not, and nothing said so. `keep_recent` defaults to null, a null keep
 * binds `NoCompaction`, and the Lab published no `context` key — so the
 * Overseer's durable thread (one permanent scope, `lab:agent`, shared by the
 * flyout, the full chat and the Studio) replayed its entire history on every
 * turn. The chat's display list is capped at 100 messages; what reached the
 * MODEL never was. The only bound was a human typing `/clear`.
 *
 * That is a dogfooding failure rather than a tuning oversight: the context
 * window is the feature this Lab exists to exercise, `CompactionRecallProbe` and
 * `SummaryLossProbe` probe it here, and the live agent beside those probes was
 * not using it.
 *
 * Found because the flabs team asked how ours was bounded, having just hit the
 * same failure from the other side — a harness session scoped by scenario rather
 * than by run, 3,580 messages on one thread, verdicts drifting.
 *
 * These tests exist so the answer is a check rather than a config comment.
 */
class OverseerContextWindowTest extends TestCase
{
    public function test_the_lab_binds_a_bounded_compaction_strategy(): void
    {
        // NoCompaction is what an unconfigured Lab gets, and it is the one
        // outcome this must never be again.
        $strategy = app(CompactionStrategy::class);

        $this->assertInstanceOf(KeepRecentTurns::class, $strategy);
    }

    public function test_the_window_is_bounded_by_a_positive_number_of_turns(): void
    {
        // The vacuity guard for the test above: `KeepRecentTurns(0)` would
        // satisfy the type and keep nothing, and a null or zero keep is exactly
        // what binds NoCompaction one layer down.
        $this->assertIsInt(config('prism-harness.context.keep_recent'));
        $this->assertGreaterThan(0, config('prism-harness.context.keep_recent'));
    }

    public function test_what_leaves_the_window_is_kept_and_reachable(): void
    {
        // A bounded window with nowhere to put what leaves it is the
        // configuration the harness's own config calls the worst available:
        // cheap window, agent blind to its own work. Both halves or neither.
        $this->assertInstanceOf(TableEvictionSink::class, app(EvictionSink::class));
        $this->assertInstanceOf(TableContextRecall::class, app(ContextRecall::class));
    }

    public function test_the_recall_budget_is_bounded_too(): void
    {
        // Compaction is the reason recall exists, so an unbounded recall would
        // re-expand the window that was just compacted — the tool undoing the
        // strategy, with nothing reporting a problem.
        $budget = config('prism-harness.context.recall_budget', 1000);

        $this->assertIsNumeric($budget);
        $this->assertGreaterThan(0, (int) $budget);
    }
}
