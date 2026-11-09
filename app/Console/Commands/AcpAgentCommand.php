<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acp\ApprovedDriver;
use App\Acp\ApprovedPrompt;
use App\Acp\ConversationCodexTransport;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Lab\SessionNotReady;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Prism\Acp\Agent\AcpAgent;
use Prism\Acp\Agent\AgentDriver;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\Stdio;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\JsonRpc\JsonRpcPeer;

/**
 * The published ACP package, hosted with the Lab's provider-specific conversation policy.
 *
 * Claude's wildcard hook denies every tool. Codex uses read-only/untrusted policy, disabled native capabilities
 * and effective MCP isolation; every permission request that reaches the client is declined.
 */
final class AcpAgentCommand extends Command
{
    protected $signature = 'lab:acp-agent {--provider=claude : claude or codex} {--approved-run= : Exact owner-approved single-turn GO reference}';

    protected $description = 'Serve a Claude or Codex ACP agent with the Lab conversation policy';

    public function handle(): int
    {
        $provider = $this->option('provider');
        if (! in_array($provider, ['claude', 'codex'], true)) {
            $this->error('Choose claude or codex.');

            return self::INVALID;
        }
        $id = $this->option('approved-run');
        if (! app()->environment('local') || ! is_string($id) || strlen($id) !== 36) {
            $this->error('paid_call_not_authorised: Supply --approved-run with the owner\'s exact approved GO.');

            return self::FAILURE;
        }
        try {
            $approval = ApprovedPrompt::acquire($id, $provider, app(RunAuthority::class), app(RunScopeFactory::class));
        } catch (PaidRunNotAuthorised|SessionNotReady|ValidationException $failure) {
            $this->error('paid_call_not_authorised: The exact approved ACP turn is unavailable or session preflight refused it.');

            return self::FAILURE;
        }
        $workspace = (string) config('acp.workspace');
        if (! is_dir($workspace)) {
            mkdir($workspace, 0700, true);
        }
        $base = $provider === 'codex' ? new CodexDriverOptions(
            cwd: $workspace,
            binary: (string) config('acp.codex.binary'),
            binaryArgs: [...array_values(array_map('strval', (array) config('acp.codex.binary_args'))), ...ConversationCodexTransport::arguments()],
            model: $approval->model,
            approvalPolicy: 'untrusted',
            sandbox: 'read-only',
        ) : new ClaudeDriverOptions(
            cwd: (string) config('acp.workspace'),
            binary: (string) config('acp.claude.binary'),
            binaryArgs: array_values(array_map('strval', (array) config('acp.claude.binary_args'))),
            permissionMode: 'dontAsk',
            permissionPrompts: ['*'],
            model: $approval->model,
            disallowedTools: ['Bash', 'Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'WebFetch', 'WebSearch', 'KillShell'],
        );
        $stderr = fopen('php://stderr', 'w');

        $served = Stdio::serve(Stdio::stdin(), STDOUT, fn (JsonRpcPeer $peer) => new AcpAgent(
            peer: $peer,
            driverFactory: function (array $session, DriverEvents $events) use ($base, $approval): AgentDriver {
                $approval->claimSession($session);
                $driver = $base instanceof CodexDriverOptions ? new CodexDriver($base->forSession($session), $events,
                    fn (CodexTransportOptions $options, CodexTransportHandlers $handlers) => new ConversationCodexTransport($options, $handlers))
                    : new ClaudeDriver($base->forSession($session), $events);

                return new ApprovedDriver($driver, $approval);
            },
            driverCapabilities: $provider === 'codex' ? CodexDriver::CAPABILITIES : [...ClaudeDriver::CAPABILITIES, 'permissionRequests' => true],
            agentInfo: ['name' => 'prism-labs', 'title' => 'Prism Lab ACP agent (PHP)', 'version' => '1'],
            onStderr: fn (string $session, string $line) => $stderr && fwrite($stderr, "[{$session}] {$line}\n"),
            onProtocolError: fn (string $session, string $problem) => $stderr && fwrite($stderr, "[{$session}] problem: {$problem}\n"),
            // Codex modelState awaits ready/history. The client times out first, so session/load cannot return
            // on the model-wait fallback and mix late replay with a newly submitted prompt.
            modelWaitSeconds: (float) config('acp.session_seconds') + 5.0,
        ));
        $served['closed']->await();

        return self::SUCCESS;
    }
}
