<?php

declare(strict_types=1);

namespace App\Acp;

use Prism\Acp\Async\Deferred;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Codex\StdioCodexTransport;
use Prism\Acp\Mcp\McpServers;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Process\ProcessTree;
use Throwable;

/** The Lab's consumer policy: disable every effective native MCP server before starting or resuming a thread. */
final class ConversationCodexTransport implements CodexTransport
{
    private readonly CodexTransport $inner;

    private readonly string $configId;

    private ?\stdClass $pending = null;

    /** @var array<string,bool> */
    public const FEATURES = [
        'shell_tool' => false, 'shell_snapshot' => false, 'multi_agent' => false, 'view_image' => false,
        'apps' => false, 'plugins' => false, 'hooks' => false, 'goals' => false, 'skill_mcp_dependency_install' => false,
    ];

    /** @param (\Closure(CodexTransportOptions,CodexTransportHandlers):CodexTransport)|null $factory */
    public function __construct(CodexTransportOptions $options, private readonly CodexTransportHandlers $handlers, ?\Closure $factory = null)
    {
        $this->configId = 'lab-mcp-isolation-'.bin2hex(random_bytes(12));
        $wrapped = new CodexTransportHandlers(
            onFrame: fn (mixed $frame) => $this->receive($frame),
            onStderr: $handlers->onStderr,
            onProblem: $handlers->onProblem,
            onExit: $handlers->onExit,
        );
        $this->inner = $factory === null ? new StdioCodexTransport($options, $wrapped) : $factory($options, $wrapped);
    }

    /** @return list<string> Host policy comes after any operator-supplied CLI arguments. */
    public static function arguments(): array
    {
        $args = ['-c', 'web_search="disabled"'];
        foreach (self::FEATURES as $name => $enabled) {
            $args[] = '-c';
            $args[] = 'features.'.$name.'='.($enabled ? 'true' : 'false');
        }

        return $args;
    }

    public function start(): Deferred
    {
        return $this->inner->start();
    }

    public function send(mixed $frame): void
    {
        $message = Json::decode(Json::encode($frame));
        if ($message instanceof \stdClass && in_array($message->method ?? null, ['thread/start', 'thread/resume'], true)) {
            if ($this->pending !== null || ! ($message->params ?? null) instanceof \stdClass || ! is_string($message->params->cwd ?? null)) {
                throw new \RuntimeException('Cannot verify Codex MCP isolation');
            }
            $this->pending = $message;
            $this->inner->send(['jsonrpc' => '2.0', 'id' => $this->configId, 'method' => 'config/read',
                'params' => ['cwd' => $message->params->cwd, 'includeLayers' => false]]);

            return;
        }
        $this->inner->send($frame);
    }

    private function receive(mixed $frame): void
    {
        if (! $frame instanceof \stdClass || ($frame->id ?? null) !== $this->configId) {
            ($this->handlers->onFrame)($frame);

            return;
        }
        $thread = $this->pending;
        $this->pending = null;
        if ($thread === null) {
            return;
        }
        try {
            $effective = $frame->result->config ?? null;
            if (isset($frame->error) || ! $effective instanceof \stdClass) {
                throw new \RuntimeException('Missing effective configuration');
            }
            $inherited = property_exists($effective, 'mcp_servers') ? $effective->mcp_servers : new \stdClass;
            $config = property_exists($thread->params, 'config') ? $thread->params->config : new \stdClass;
            if (! $inherited instanceof \stdClass || ! $config instanceof \stdClass) {
                throw new \RuntimeException('Invalid MCP configuration');
            }
            $explicit = property_exists($config, 'mcp_servers') ? $config->mcp_servers : new \stdClass;
            if (! $explicit instanceof \stdClass) {
                throw new \RuntimeException('Invalid explicit MCP configuration');
            }
            $names = array_values(array_unique([...array_keys(get_object_vars($inherited)), ...array_keys(get_object_vars($explicit))]));
            $config = McpServers::codexDisabledConfig($config, $names, $inherited);
            $features = $config->features ?? new \stdClass;
            if (! $features instanceof \stdClass) {
                throw new \RuntimeException('Invalid native features');
            }
            $config->features = (object) [...get_object_vars($features), ...self::FEATURES];
            $config->web_search = 'disabled';
            $thread->params->config = $config;
            $thread->params->approvalPolicy = 'untrusted';
            $thread->params->sandbox = 'read-only';
            $this->inner->send($thread);
        } catch (Throwable) {
            // Native config may contain credentials. Only this generic failure crosses back to the driver.
            ($this->handlers->onFrame)((object) ['jsonrpc' => '2.0', 'id' => $thread->id,
                'error' => (object) ['code' => -32603, 'message' => 'Codex MCP isolation could not be verified']]);
        }
    }

    public function close(): void
    {
        $this->pending = null;
        $this->inner->close();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        $this->pending = null;

        return $this->inner->stop($graceMs);
    }

    public function processTree(): ?ProcessTree
    {
        return $this->inner->processTree();
    }
}
