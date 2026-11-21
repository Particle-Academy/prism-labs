<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Models\User;
use Prism\AgentDocs\DocsAgent;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\Harness\PrismHarness;
use Throwable;

/**
 * One edit, exactly as a host makes it: the agent's public `edit()` on a
 * DocumentStore whose two slots hold the scenario's saved file and pending
 * draft. Both lanes call this; they differ only in which model answers.
 */
final class Exercise
{
    /** The lab's own participant, as the Lab's probes do it: a local user that can never log in. */
    public static function participant(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'docs-lab@localhost'],
            ['name' => 'Docs Lab', 'password' => bcrypt(bin2hex(random_bytes(16)))],
        );
    }

    public static function run(DocsAgent $agent, PrismHarness $harness, string $agentName, Scenario $scenario, Fixtures $fixtures, string $scope): LaneRun
    {
        $saved = $scenario->store['saved'] === null ? null : $fixtures->docx($scenario->store['saved']);
        $draft = $scenario->store['draft'] === null ? null : $fixtures->docx($scenario->store['draft']);
        $store = new InMemoryDocumentStore($saved, $draft);
        $participant = self::participant();
        $result = null;
        $error = null;
        $stop = false;
        try {
            $result = $agent->edit($participant, $store, $scenario->brief, new DocsOptions, $scope);
        } catch (Throwable $e) {
            report($e);
            $error = Failure::code($e);
            $stop = Failure::stopsRun($e);
        }
        $transcript = ['shown' => '', 'calls' => [], 'model_calls' => 0];
        try {
            $transcript = LaneRun::transcript($harness->forAgent($agentName)->session($participant, $scope)->thread()->messages());
        } catch (Throwable $e) {
            report($e);
            $error ??= 'error:transcript_unreadable';
        }

        return new LaneRun($result, $saved, $store->draft(), $transcript['shown'],
            $transcript['calls'], $transcript['model_calls'], $error, $stop);
    }
}
