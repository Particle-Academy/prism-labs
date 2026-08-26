<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Team\Coordinator;
use Illuminate\Console\Command;

/**
 * The team's command line.
 *
 * `team:roster` probes every addressable lane and prints what came back;
 * `team:ask` puts a question to Prism.php and lets it delegate.
 *
 * Both exist so the team is usable without the board. A subsystem that can
 * only be driven through its own UI cannot be scripted, cannot run in CI, and
 * cannot be debugged when the UI is the thing that is broken.
 */
final class TeamCommand extends Command
{
    protected $signature = 'team:roster';

    protected $description = 'Probe every language agent and report what each one says about itself';

    public function handle(Coordinator $coordinator): int
    {
        $rows = [];

        foreach ($coordinator->roster() as $agent) {
            $reported = $agent['reported'] ?? null;

            $rows[] = [
                $agent['name'],
                $agent['state_label'],
                $agent['repo'] ?? '—',
                $reported['port_version'] ?? '—',
                match (true) {
                    ($reported['can_reason'] ?? null) === true => 'yes',
                    ($reported['can_reason'] ?? null) === false => 'no key',
                    default => '—',
                },
                // Truncated: a connection failure message can be a paragraph,
                // and the table is for scanning, not for reading a stack.
                isset($agent['reason']) ? mb_substr((string) $agent['reason'], 0, 44) : '',
            ];
        }

        $this->table(['Agent', 'State', 'Repo', 'Port', 'Reasons', 'Note'], $rows);

        return self::SUCCESS;
    }
}
