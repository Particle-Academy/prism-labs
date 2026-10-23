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
 * hosted the way any embedder would host it. The package ships no host of its own (neither does the TypeScript
 * reference); this is the Lab's.
 *
 * The agent may read and answer, and nothing more: `dontAsk` refuses every tool call that would need a person, and
 * the tools that change things or reach out are disallowed outright, so a prompt typed into a Lab page cannot write
 * a file or run a command on this machine.
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
            model: (string) config('acp.claude.model'),
            disallowedTools: ['Bash', 'Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'WebFetch', 'WebSearch', 'KillShell'],
        );
        $stderr = fopen('php://stderr', 'w');

        $served = Stdio::serve(Stdio::stdin(), STDOUT, fn (JsonRpcPeer $peer) => new AcpAgent(
            peer: $peer,
            driverFactory: fn (array $session, DriverEvents $events) => new ClaudeDriver($base->forSession($session), $events),
            driverCapabilities: ClaudeDriver::CAPABILITIES,
            agentInfo: ['name' => 'prism-labs', 'title' => 'Prism Lab ACP agent (PHP)', 'version' => '1'],
            onStderr: fn (string $session, string $line) => $stderr && fwrite($stderr, "[{$session}] {$line}\n"),
            onProtocolError: fn (string $session, string $problem) => $stderr && fwrite($stderr, "[{$session}] problem: {$problem}\n"),
        ));
        $served['closed']->await();

        return self::SUCCESS;
    }
}
