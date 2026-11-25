<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Console\Commands\DocsRunCommand;
use App\DocsLab\Scenarios;
use App\DocsLab\ScriptedModel;
use App\Lab\ModelPrices;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\LabRunIntent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The live lane, exercised with FAKES only: a real owner approval and GO
 * through RunAuthority, the real preflight (its GitHub/worker evidence faked by
 * ApprovesLabRuns), and Prism's fake provider answering every model call. No
 * test here reaches a provider.
 */
trait DocsLabLive
{
    use ApprovesLabRuns;
    use CachesModelRoster;
    use DocsLabDatabase;

    protected function setUpDocsLabLive(): void
    {
        // List prices are dated; pin the clock inside their freshness window
        // so this suite does not start failing when the real list goes stale.
        $this->travelTo(CarbonImmutable::parse(ModelPrices::CHECKED_ON)->addDay()->setTime(12, 0));
        $this->setUpDocsLab();
        $this->prepareLabAuthority();
        $this->cacheModelRoster();
        DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'],
            ['value' => json_encode(['anthropic:claude-sonnet-5-5', 'anthropic:claude-haiku-5-5'], JSON_THROW_ON_ERROR)]);
    }

    /** The owner prepares, approves and gives GO for exactly this live input and cap. */
    protected function approveLive(array $input, string $capUsd): LabRunIntent
    {
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            $authority = app(RunAuthority::class);
            $intent = $authority->draft(app(RunScopeFactory::class)->fromAction('cli.docs.run', 'lab:agent', $input), $capUsd);
            $authority->approve($intent->id, $intent->digest);

            return $authority->go($intent->id, $intent->digest, $capUsd, 'GO');
        } finally {
            Auth::guard('web')->logout();
        }
    }

    protected function liveInput(string $scenario = 'all', bool $judge = true, string $model = 'claude-sonnet-5-5'): array
    {
        return DocsRunCommand::liveInput($scenario, $model, $judge, null);
    }

    /**
     * Prism's fake, scripted with every selected scenario's turns in run order
     * and one judge verdict per scenario.
     */
    protected function scriptLive(string $selection = 'all', string $verdict = 'pass'): ScriptedModel
    {
        $turns = [];
        $verdicts = [];
        foreach (app(Scenarios::class)->select($selection) as $scenario) {
            array_push($turns, ...ScriptedModel::fromScenario($scenario->scripted));
            $verdicts[] = ['verdict' => $verdict, 'reason' => 'Scripted verdict for '.$scenario->id.'.'];
        }

        return ScriptedModel::install($turns, $verdicts);
    }
}
