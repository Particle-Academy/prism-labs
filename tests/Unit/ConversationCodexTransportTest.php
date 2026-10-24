<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Acp\ConversationCodexTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Codex\CodexTransport;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Ndjson\Json;
use Prism\Acp\Process\ProcessTree;
use Prism\Acp\Process\StopVerdict;

final class ConversationCodexTransportTest extends TestCase
{
    private RecordingLabCodexTransport $native;

    private array $received = [];

    private function bridge(): ConversationCodexTransport
    {
        return new ConversationCodexTransport(new CodexTransportOptions('C:/owned-fixture', []),
            new CodexTransportHandlers(
                onFrame: function (mixed $frame): void {
                    $this->received[] = $frame;
                },
                onStderr: fn (string $line) => null,
                onProblem: fn (string $problem, ?bool $recoverable) => null,
                onExit: fn (?int $code, ?string $signal) => null,
            ),
            function (CodexTransportOptions $options, CodexTransportHandlers $handlers): CodexTransport {
                return $this->native = new RecordingLabCodexTransport($handlers);
            });
    }

    #[DataProvider('threadMethods')]
    public function test_effective_and_explicit_servers_are_disabled_before_the_thread_is_forwarded(string $method): void
    {
        $bridge = $this->bridge();
        $bridge->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => $method, 'params' => [
            'cwd' => 'C:/owned-fixture', 'approvalPolicy' => 'never', 'sandbox' => 'danger-full-access',
            'config' => ['features' => ['unrelated' => true, 'shell_tool' => true], 'web_search' => 'live',
                'mcp_servers' => ['explicit' => ['url' => 'http://127.0.0.1:1']]],
        ]]);
        $this->assertCount(1, $this->native->sent);
        $query = $this->native->sent[0];
        $this->assertSame('config/read', $query->method);
        $this->assertSame('C:/owned-fixture', $query->params->cwd);
        $this->native->emit(['jsonrpc' => '2.0', 'id' => $query->id, 'result' => ['config' => [
            'private_fixture' => 'dummy-sensitive-config', 'mcp_servers' => [
                'stdio' => ['command' => 'owned-fixture-command', 'args' => []],
                'http' => ['url' => 'https://owned-fixture.invalid/mcp', 'http_headers' => ['X-Dummy' => 'dummy-sensitive-config']],
            ],
        ]]]);
        $this->assertCount(2, $this->native->sent);
        $thread = $this->native->sent[1];
        $this->assertSame($method, $thread->method);
        $this->assertSame(3, $thread->id);
        foreach (['stdio', 'http', 'explicit'] as $name) {
            $this->assertFalse($thread->params->config->mcp_servers->{$name}->enabled);
        }
        $this->assertFalse(isset($thread->params->config->mcp_servers->stdio->url));
        $this->assertSame('owned-fixture-command', $thread->params->config->mcp_servers->stdio->command);
        $this->assertSame('untrusted', $thread->params->approvalPolicy);
        $this->assertSame('read-only', $thread->params->sandbox);
        $this->assertSame('disabled', $thread->params->config->web_search);
        $this->assertFalse($thread->params->config->features->shell_tool);
        $this->assertFalse($thread->params->config->features->apps);
        $this->assertFalse($thread->params->config->features->multi_agent);
        $this->assertTrue($thread->params->config->features->unrelated);
        $this->assertStringNotContainsString('dummy-sensitive-config', Json::encode($this->native->sent));
        $this->assertSame([], $this->received, 'the private config reply must not reach the driver');
    }

    public static function threadMethods(): array
    {
        return [['thread/start'], ['thread/resume']];
    }

    #[DataProvider('badReplies')]
    public function test_an_unverifiable_config_never_starts_a_thread_and_has_a_generic_error(array $reply): void
    {
        $bridge = $this->bridge();
        $bridge->send(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'thread/start', 'params' => ['cwd' => 'C:/owned-fixture']]);
        $this->native->emit(['jsonrpc' => '2.0', 'id' => $this->native->sent[0]->id, ...$reply]);
        $this->assertCount(1, $this->native->sent, 'no thread request may cross the failed policy check');
        $this->assertCount(1, $this->received);
        $this->assertSame(7, $this->received[0]->id);
        $this->assertSame(-32603, $this->received[0]->error->code);
        $this->assertStringNotContainsString('dummy-sensitive-config', Json::encode($this->received));
    }

    public static function badReplies(): array
    {
        return [
            'missing' => ['reply' => ['result' => []]],
            'null config' => ['reply' => ['result' => ['config' => null]]],
            'null map' => ['reply' => ['result' => ['config' => ['mcp_servers' => null, 'private_fixture' => 'dummy-sensitive-config']]]],
            'invalid entry' => ['reply' => ['result' => ['config' => ['mcp_servers' => ['broken' => 'dummy-sensitive-config']]]]],
            'invalid transport' => ['reply' => ['result' => ['config' => ['mcp_servers' => ['broken' => ['headers' => 'dummy-sensitive-config']]]]]],
            'native error' => ['reply' => ['error' => ['code' => -1, 'message' => 'dummy-sensitive-config']]],
        ];
    }
}

final class RecordingLabCodexTransport implements CodexTransport
{
    public array $sent = [];

    public function __construct(private readonly CodexTransportHandlers $handlers) {}

    public function start(): Deferred
    {
        $done = new Deferred;
        $done->resolve(null);

        return $done;
    }

    public function send(mixed $frame): void
    {
        $this->sent[] = Json::decode(Json::encode($frame));
    }

    public function emit(array $frame): void
    {
        ($this->handlers->onFrame)(Json::decode(Json::encode($frame)));
    }

    public function close(): void {}

    public function stop(int $graceMs = 5000): Deferred
    {
        $done = new Deferred;
        $done->resolve(new StopVerdict(true, true, 0, true));

        return $done;
    }

    public function processTree(): ?ProcessTree
    {
        return null;
    }
}
