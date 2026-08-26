<?php

declare(strict_types=1);

namespace App\Providers;

use App\Learnings\LearningStore;
use App\Team\AgentRoster;
use Illuminate\Support\ServiceProvider;

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
    }
}
