<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrity\FactChecker;
use App\Learnings\LearningStore;
use App\Team\AgentRoster;
use App\Team\Coordinator;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Tools\ToolRegistry;

final class TeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: the roster memoises, and two rosters disagreeing about
        // who is on the team is exactly the confusion a board should not have.
        $this->app->singleton(AgentRoster::class);

        $this->app->singleton(LearningStore::class, fn (): LearningStore => new LearningStore(
            (string) config('team.learnings_path'),
        ));

        $this->app->singleton(FactChecker::class, fn (): FactChecker => new FactChecker(
            (string) config('team.factcheck.script'),
            (int) config('team.factcheck.timeout'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->bound(ToolRegistry::class)) {
            $this->app->make(ToolRegistry::class)->registerMany(
                $this->app->make(Coordinator::class)->tools(),
            );
        }
    }
}
