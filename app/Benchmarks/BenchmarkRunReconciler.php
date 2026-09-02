<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkRun;
use Illuminate\Support\Facades\DB;

final class BenchmarkRunReconciler
{
    public function __construct(private readonly BenchmarkLearningRecorder $learnings) {}

    public function reconcile(BenchmarkRun $run): BenchmarkRun
    {
        $reconciled = DB::transaction(function () use ($run): ?BenchmarkRun {
            $locked = BenchmarkRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === 'cancelled') {
                // The fuse records its own learning when it trips, so a
                // cancelled run is already accounted for by the time any late
                // lane reconciles behind it.
                return null;
            }

            $statuses = $locked->lanes()->pluck('status');
            if ($statuses->contains(fn (string $status): bool => in_array($status, ['queued', 'running'], true))) {
                return null;
            }

            $locked->forceFill([
                'status' => $statuses->contains('failed') ? 'failed' : 'completed',
                'finished_at' => now(),
            ])->save();

            return $locked->refresh();
        });

        if (! $reconciled instanceof BenchmarkRun) {
            return BenchmarkRun::query()->findOrFail($run->id);
        }

        // Outside the transaction: a 0L that cannot be written must not roll
        // back the status that says what happened.
        $this->learnings->record($reconciled);

        return $reconciled->refresh();
    }
}
