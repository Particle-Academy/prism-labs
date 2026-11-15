<?php

declare(strict_types=1);

namespace App\Providers;

use App\Lab\WorkerHeartbeats;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class LabPreflightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeats::class);
    }

    public function boot(): void
    {
        Event::listen(Looping::class, [WorkerHeartbeats::class, 'looping']);
        Event::listen(JobProcessing::class, [WorkerHeartbeats::class, 'processing']);
        Event::listen(JobProcessed::class, [WorkerHeartbeats::class, 'processed']);
        Event::listen(WorkerStopping::class, [WorkerHeartbeats::class, 'stopping']);
    }
}
