<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkRun;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

final class BenchmarkFuse
{
    public const REASON = 'Emergency stop requested by the operator.';

    public function __construct(private readonly BenchmarkLearningRecorder $learnings) {}

    /**
     * Fail closed and idempotently. Fancy Flow jobs re-read their run and no-op
     * when it is terminal, so this also neutralizes jobs already on the queue.
     */
    public function trip(BenchmarkRun $run, string $reason = self::REASON): BenchmarkRun
    {
        $tripped = DB::transaction(function () use ($run, $reason): BenchmarkRun {
            /** @var BenchmarkRun $locked */
            $locked = BenchmarkRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === 'cancelled') {
                return $locked->load('lanes');
            }

            $now = now();
            $workflowIds = $locked->lanes()->whereNotNull('workflow_run_id')->pluck('workflow_run_id');

            WorkflowRun::query()
                ->whereIn('id', $workflowIds)
                ->whereNotIn('status', [WorkflowRun::COMPLETED, WorkflowRun::FAILED, WorkflowRun::SKIPPED])
                ->update([
                    'status' => WorkflowRun::FAILED,
                    'error' => $reason,
                    'updated_at' => $now,
                ]);

            $locked->lanes()
                ->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now]);

            $locked->forceFill([
                'status' => 'cancelled',
                'finished_at' => $now,
                'cancelled_at' => $now,
                'cancel_reason' => mb_substr($reason, 0, 500),
            ])->save();

            return $locked->load('lanes');
        });

        // A stopped run files a 0L like any other terminal run. Without this
        // the emergency stop is the one outcome that leaves nothing behind —
        // and the spend and elapsed time are already gone by the time it is
        // pressed, so the reason it was pressed is the only salvageable part.
        // Outside the transaction, and the recorder never throws.
        $this->learnings->record($tripped);

        return $tripped->refresh()->load('lanes');
    }

    public function purge(BenchmarkRun $run): void
    {
        DB::transaction(function () use ($run): void {
            /** @var BenchmarkRun $locked */
            $locked = BenchmarkRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, ['running', 'ready'], true)) {
                throw new \LogicException('Trip the emergency fuse before deleting an active run.');
            }

            $flows = WorkflowRun::query()->whereIn('id', $locked->lanes()->whereNotNull('workflow_run_id')->pluck('workflow_run_id'))->get();
            DB::table('fancy_flow_workflow_run_nodes')->whereIn('run_key', $flows->pluck('run_key'))->delete();
            WorkflowRun::query()->whereIn('id', $flows->pluck('id'))->delete();
            $locked->delete();
        });
    }

    public function clear(string $scope): int
    {
        $statuses = match ($scope) {
            'queued' => ['queued'],
            'settled' => ['completed', 'failed', 'cancelled'],
            default => throw new \InvalidArgumentException('Unknown benchmark cleanup scope.'),
        };

        $runs = BenchmarkRun::query()->whereIn('status', $statuses)->get();
        foreach ($runs as $run) {
            $this->purge($run);
        }

        return $runs->count();
    }
}
