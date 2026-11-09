<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Consensus\ConsensusCoordinator;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\SessionNotReady;
use App\Models\ConsensusRun;
use App\Models\LabPaidWork;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CollectConsensusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public ?string $paidWorkId = null;

    /** @param array<string, mixed> $evidence */
    public function __construct(public readonly string $runId, public readonly array $evidence, ?string $paidWorkId = null)
    {
        $this->paidWorkId = $paidWorkId;
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
        if ($run === null || $run->status !== 'collecting') {
            return;
        }
        $started = false;
        try {
            if ($this->paidWorkId === null || $run->paid_work_id !== $this->paidWorkId) {
                throw new PaidRunNotAuthorised;
            }
            app(PaidWorkRegistry::class)->run($this->paidWorkId, 'consensus.collect', $run->id,
                ['question' => $run->question, 'evidence' => $this->evidence, 'approved_run_id' => $run->approved_run_id],
                function () use ($coordinator, $run, &$started): void {
                    app(PaidRunContext::class)->requireActions(['consensus.collect']);
                    $started = true;
                    $coordinator->collectPending($run, $this->evidence);
                });
        } catch (PaidRunNotAuthorised|SessionNotReady) {
            // A duplicate must not abandon a collection already claimed by
            // another delivery. Missing/changed authority still stays unpaid.
            DB::transaction(function () use ($coordinator, $run, $started): void {
                $work = LabPaidWork::query()->whereKey($run->paid_work_id)->lockForUpdate()->first();
                $current = ConsensusRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if ($current?->status === 'collecting' && ($started || $work === null || $work->claimed_at === null)) {
                    $coordinator->abandon($current, 'The approved run authority or current session readiness is unavailable. Collection stopped.');
                }
            });
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
