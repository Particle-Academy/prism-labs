<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benchmarks\BenchmarkScorer;
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

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $laneId)
    {
        $this->onQueue('scoring');
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

        if ($lane instanceof BenchmarkLane) {
            $scorer->score($lane);
        }
    }
}
