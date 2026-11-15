<?php

declare(strict_types=1);

// Only native-acp-policy.mjs invokes this host, with an owned home and loopback model/MCP fixtures.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Acp\ConversationCodexTransport;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Ndjson\Json;

$options = Json::decode((string) file_get_contents($argv[1]));
$loop = Loop::get();
$done = new Deferred($loop);
$problems = [];
$approvals = 0;
$guarded = $options->guarded;
$driver = new CodexDriver(new CodexDriverOptions(
    cwd: $options->cwd, binary: $options->binary, model: 'fixture',
    binaryArgs: $guarded || $options->kind === 'mcp' ? ConversationCodexTransport::arguments() : [],
    sandbox: $options->kind === 'shell' && ! $guarded ? 'danger-full-access' : 'read-only',
    approvalPolicy: $options->kind === 'shell' && ! $guarded ? 'never' : 'untrusted',
    parentEnv: [...getenv(), 'CODEX_HOME' => $options->home],
    resumeSessionId: $options->resumeSessionId ?? null,
), new DriverEvents(
    onProtocolError: function (string $problem) use (&$problems): void {
        $problems[] = $problem;
    },
    onRequestPermission: function () use ($loop, &$approvals): Deferred {
        $approvals++;
        $answer = new Deferred($loop);
        $answer->resolve(['outcome' => 'cancelled']);

        return $answer;
    },
    onTurnEnd: fn (TurnOutcome $outcome) => $done->resolve($outcome),
    onExit: function () use ($done): void {
        if (! $done->isSettled()) {
            $done->reject(new RuntimeException('Native provider exited before the fixture turn'));
        }
    },
), transportFactory: $guarded ? fn (CodexTransportOptions $o, CodexTransportHandlers $h) => new ConversationCodexTransport($o, $h) : null, loop: $loop);
$timeout = $loop->delay(35, static function (): void {
    throw new RuntimeException('Native policy fixture timeout');
});
try {
    $driver->start();
    $driver->ready()->await();
    $driver->prompt('Run the deterministic loopback policy control.');
    $outcome = $done->await();
    fwrite(STDOUT, Json::encode(['outcome' => ['isError' => $outcome->isError, 'stopReason' => $outcome->stopReason],
        'sessionId' => $driver->cliSessionId(), 'problems' => $problems, 'approvals' => $approvals])."\n");
} finally {
    $loop->cancel($timeout);
    fwrite(STDOUT, Json::encode(['stop' => $driver->stop(0)->await()->toArray()])."\n");
}
