<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkRun;
use Illuminate\Support\Facades\DB;

final class BenchmarkRunReconciler
{
    public function reconcile(BenchmarkRun $run): BenchmarkRun
    {
        return DB::transaction(function () use ($run): BenchmarkRun {
            $locked = BenchmarkRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $statuses = $locked->lanes()->pluck('status');
            if ($statuses->contains(fn (string $status): bool => in_array($status, ['queued', 'running'], true))) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $statuses->contains('failed') ? 'failed' : 'completed',
                'finished_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }
}
