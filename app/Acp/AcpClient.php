<?php

declare(strict_types=1);

namespace App\Acp;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Env\ChildEnv;
use Prism\Acp\JsonRpc\JsonRpcPeer;
use Prism\Acp\Ndjson\NdjsonFramer;
use Prism\Acp\Process\Provider;
use Prism\Acp\Process\ProviderOnLoop;
use Prism\Acp\Process\StopVerdict;

/**
 * The Lab as an ACP CLIENT: it starts the agent (`php artisan lab:acp-agent`) and speaks ACP to it over its stdio,
 * as an editor or Genie would.
 *
 * Built from prism-acp's own primitives rather than added to the package, because the reference has no client
 * either: what a Lab page needs that no other consumer does belongs in the Lab. The agent host runs under the
 * package's process-tree containment like any provider -- its whole tree (the host, the Claude CLI, anything the CLI
 * starts) ends when this client stops it, and its stdio arrives as sockets the event loop can wait on, which a pipe
 * on Windows is not. That is what gives every request below a real timeout.
 */
final class AcpClient
{
    private readonly Loop $loop;

    private readonly JsonRpcPeer $peer;

    private readonly ProviderOnLoop $host;

    private readonly NdjsonFramer $framer;

    private string $stderr = '';

    private bool $closed = false;

    /**
     * @param  \Closure(mixed): void  $onUpdate  each `session/update` notification's params
     * @param  \Closure(mixed): mixed  $onPermission  answers `session/request_permission`
     */
    public function __construct(\Closure $onUpdate, \Closure $onPermission, string $provider = 'claude')
    {
        if (! in_array($provider, ['claude', 'codex'], true)) {
            throw new \InvalidArgumentException('Unsupported ACP provider');
        }
        $this->loop = new Loop;
        $this->framer = new NdjsonFramer;
        $env = ChildEnv::build(getenv(), ['CLAUDE_CONFIG_DIR', 'CODEX_HOME', 'CODEX_CONFIG_DIR', 'XDG_CONFIG_HOME', 'LAB_ACP_CLAUDE_BINARY', 'LAB_ACP_CLAUDE_ARGS', 'LAB_ACP_MODEL', 'LAB_ACP_CODEX_BINARY', 'LAB_ACP_CODEX_ARGS', 'LAB_ACP_CODEX_MODEL', 'LAB_ACP_SESSION_SECONDS', 'APP_ENV'])->env;
        $process = Provider::launch(PHP_BINARY, ['artisan', 'lab:acp-agent', '--provider='.$provider], base_path(), $env);

        $this->peer = new JsonRpcPeer(
            send: fn (mixed $message) => $process->write(NdjsonFramer::encodeLine($message)),
            loop: $this->loop,
        );
        $this->peer->onNotify('session/update', $onUpdate);
        $this->peer->handle('session/request_permission', $onPermission);

        $this->host = new ProviderOnLoop($process, $this->loop,
            onStdout: function (string $chunk): void {
                foreach ($this->framer->push($chunk) as $frame) {
                    if ($frame->ok) {
                        $this->peer->receive($frame->value);
                    }
                }
            },
            onStderr: function (string $chunk): void {
                $this->stderr = substr($this->stderr.$chunk, -20_000);
            },
            onExited: fn (int $code) => null,
            onClosed: function (): void {
                $this->closed = true;
                $this->peer->fail(new \RuntimeException('the agent exited'));
            },
        );
    }

    /** Send a request and wait for its result, at most $seconds. */
    public function request(string $method, mixed $params, float $seconds): mixed
    {
        $answer = $this->peer->request($method, $params);
        $timer = $this->loop->delay($seconds, fn () => $answer->isSettled() ? null : $answer->reject(new \RuntimeException("{$method} did not answer within {$seconds} s")));
        try {
            return $answer->await();
        } finally {
            $this->loop->cancel($timer);
        }
    }

    /** Start a request without waiting: a prompt runs while the loop delivers its updates. */
    public function send(string $method, mixed $params): Deferred
    {
        return $this->peer->request($method, $params);
    }

    public function notify(string $method, mixed $params): void
    {
        $this->peer->notify($method, $params);
    }

    /** Run the loop until $done says so or $seconds pass; true when $done. */
    public function runUntil(\Closure $done, float $seconds): bool
    {
        $end = microtime(true) + $seconds;
        $this->loop->run(fn (): bool => $done() || microtime(true) > $end || $this->closed);

        return $done();
    }

    /** The host's pid, and what holds its tree. */
    public function tree(): array
    {
        $tree = $this->host->provider->tree();

        return ['host_pid' => $tree->providerPid, 'supervisor_pid' => $tree->supervisorPid, 'containment' => $tree->containment];
    }

    /** End the agent: close its input (it ends its sessions), give it the grace, end the tree, and say what was left. */
    public function stop(int $graceMs = 5000): StopVerdict
    {
        $verdict = $this->host->stop($graceMs)->await();
        if (! $verdict instanceof StopVerdict) {
            throw new \RuntimeException('the stop gave no verdict');
        }

        return $verdict;
    }

    /** The last of what the host wrote to stderr: the agent's own diagnostics. */
    public function stderr(): string
    {
        return $this->stderr;
    }
}
