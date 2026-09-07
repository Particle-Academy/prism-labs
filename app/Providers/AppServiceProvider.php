<?php

namespace App\Providers;

use App\Context\TableContextRecall;
use App\Context\TableEvictionSink;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Lab's answer to the harness's two open contracts.
        //
        // The harness ships no sink but the one that discards, and no recall at
        // all, because it will not decide that somebody's conversation is
        // disposable for them. These are the Lab's decisions, made out loud:
        // evicted turns go to a table, and the agent can look them up.
        //
        // Bound in register() so both are present before boot resolves tools --
        // the recall tool is decided per session, but an application binding
        // late is a footgun worth not having.
        $this->app->singleton(EvictionSink::class, fn (): EvictionSink => new TableEvictionSink);
        $this->app->singleton(ContextRecall::class, fn (): ContextRecall => new TableContextRecall);

        if ($this->app->environment('local')) {
            $this->app->register(PrismLabServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
