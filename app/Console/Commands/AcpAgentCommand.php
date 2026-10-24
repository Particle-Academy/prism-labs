<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\Stdio;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\JsonRpc\JsonRpcPeer;

/**
 * An ACP agent on this process's stdin and stdout: particle-academy/prism-acp's AcpAgent with its Claude driver,
 * hosted with the Lab's policy instead of the package CLI's interactive permission policy.
 *
 * Every tool call is handed to the Lab client, which always denies it, including future tools regardless of the
 * signed-in user's global Claude permissions. This surface exercises conversational turns without executing tools.
 */
final class AcpAgentCommand extends Command
{
    protected $signature = 'lab:acp-agent';

    protected $description = 'Serve an ACP agent (prism-acp, Claude driver) on stdin and stdout';

    public function handle(): int
    {
        $workspace = (string) config('acp.workspace');
        if (! is_dir($workspace)) {
            mkdir($workspace, 0700, true);
        }
        $base = new ClaudeDriverOptions(
            cwd: (string) config('acp.workspace'),
            binary: (string) config('acp.claude.binary'),
            binaryArgs: array_values(array_map('strval', (array) config('acp.claude.binary_args'))),
            permissionMode: 'dontAsk',
            permissionPrompts: ['*'],
            model: (string) config('acp.claude.model'),
            disallowedTools: ['Bash', 'Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'WebFetch', 'WebSearch', 'KillShell'],
        );
        $stderr = fopen('php://stderr', 'w');

        $served = Stdio::serve(Stdio::stdin(), STDOUT, fn (JsonRpcPeer $peer) => new AcpAgent(
            peer: $peer,
            driverFactory: fn (array $session, DriverEvents $events) => new ClaudeDriver($base->forSession($session), $events),
            driverCapabilities: [...ClaudeDriver::CAPABILITIES, 'permissionRequests' => true],
            agentInfo: ['name' => 'prism-labs', 'title' => 'Prism Lab ACP agent (PHP)', 'version' => '1'],
            onStderr: fn (string $session, string $line) => $stderr && fwrite($stderr, "[{$session}] {$line}\n"),
            onProtocolError: fn (string $session, string $problem) => $stderr && fwrite($stderr, "[{$session}] problem: {$problem}\n"),
        ));
        $served['closed']->await();

        return self::SUCCESS;
    }
}
