<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Consensus\ConsensusCoordinator;
use App\Models\ConsensusRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class CollectConsensusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param array<string, mixed> $evidence */
    public function __construct(public readonly string $runId, public readonly array $evidence)
    {
        // Keep interactive requests off the queue occupied by benchmark lanes.
        $this->onQueue('overseer');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('consensus:'.$this->runId))->dontRelease()->expireAfter(330)];
    }

    public function handle(ConsensusCoordinator $coordinator): void
    {
        $run = ConsensusRun::query()->find($this->runId);
        if ($run !== null) {
            $coordinator->collectPending($run, $this->evidence);
        }
    }

    public function failed(?Throwable $failure): void
    {
        $run = ConsensusRun::query()->find($this->runId);
        if ($run?->status === 'collecting') {
            app(ConsensusCoordinator::class)->abandon($run, 'Collection worker failed or exceeded its time limit. No consensus was reached.');
        }
    }
}
