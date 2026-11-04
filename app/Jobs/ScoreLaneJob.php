<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benchmarks\BenchmarkScorer;
use App\Benchmarks\LaneActivity;
use App\Models\BenchmarkLane;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Score one completed lane against its rubric.
 *
 * On the `scoring` queue, NOT `default`. `default` is occupied by the lanes
 * themselves for the length of a run, so a scoring job queued there would wait
 * behind every remaining lane — and on a multi-lane run the first lane's score
 * would arrive only after the last lane had finished building.
 */
final class ScoreLaneJob implements ShouldQueue
{
    use Queueable;

    /** Releases are pre-inference only; one uncaught failure must never repeat inference. */
    public int $tries = 16;

    public int $maxExceptions = 1;

    public ?int $listingRetryDeadline = null;

    public int $timeout = 180;

    public function __construct(public readonly string $laneId)
    {
        $this->onQueue('scoring');
        $this->listingRetryDeadline = now()->addMinutes(30)->getTimestamp();
    }

    /**
     * One judgement per lane at a time. Reconciliation and a manual re-score
     * can both reach this, and two judges scoring the same lane concurrently
     * would each write a total over the other's dimensions.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->laneId))->dontRelease()];
    }

    public function handle(BenchmarkScorer $scorer): void
    {
        $lane = BenchmarkLane::query()->with(['receipts', 'benchmarkRun.spec'])->find($this->laneId);

        if (! $lane instanceof BenchmarkLane || $lane->score !== null) {
            return;
        }

        $result = $scorer->score($lane);
        $activity = app(LaneActivity::class);
        // Jobs queued before this field existed get a bounded window on first execution.
        $this->listingRetryDeadline ??= now()->addMinutes(30)->getTimestamp();
        if ($result->retryListing && $this->attempts() < $this->tries && now()->getTimestamp() < $this->listingRetryDeadline) {
            $activity->record($lane, 'scoring.deferred', "Scoring deferred: couldn't reach the provider's model list. Retrying in two minutes, for at most thirty minutes.");
            $this->release(120);

            return;
        }

        $activity->record($lane, $result->score !== null ? 'scoring.completed' : 'scoring.unavailable',
            $result->score !== null ? 'Independent scoring completed.' : ($result->reason ?? 'Not scored: no judgement is available.'));
    }
}
