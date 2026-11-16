<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\BillingReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily billing calibration (G-291). Free to run: it only reads the providers'
 * billing endpoints, so it spends no tokens.
 *
 * The payload is EMPTY on purpose. The admin keys are read from config inside
 * the clients at handle time, so a queued copy of this job — in the jobs table,
 * a failed_jobs row, a queue dashboard — carries no secret.
 */
final class ReconcileProviderBillingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(BillingReconciler $reconciler): void
    {
        $reconciler->run(CarbonImmutable::now());
    }
}
