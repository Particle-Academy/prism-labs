<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Acp\ConversationCodexTransport;
use App\Acp\HostEnvironment;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunScopeFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Async\Loop;
use Prism\Acp\Claude\ClaudeDriver;
use Prism\Acp\Claude\ClaudeDriverOptions;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Env\ChildEnv;
use Tests\TestCase;

final class AcpHostEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private array $originalConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfiguration = config()->all();
    }

    protected function tearDown(): void
    {
        // RefreshDatabase owns only the original in-memory connection.
        $this->app['config']->set($this->originalConfiguration);
        parent::tearDown();
    }

    public function test_host_uses_effective_database_key_owner_and_cli_configuration_without_inheriting_an_ambient_database(): void
    {
        $this->hostConfiguration();
        $host = HostEnvironment::build([
            'PATH' => 'owned-path', 'DB_URL' => 'private-ambient-url', 'DB_PASSWORD' => 'private-ambient-password',
            'LAB_DB_DATABASE' => 'private-ambient-database', 'APP_KEY' => 'private-ambient-key',
            'OPENAI_API_KEY' => 'private-ambient-provider-key', 'NODE_OPTIONS' => '--require=private-loader',
            'CLAUDE_CONFIG_DIR' => 'owned-claude-home', 'CODEX_HOME' => 'owned-codex-home',
        ]);
        $this->assertSame('local', $host['APP_ENV']);
        $this->assertSame('false', $host['APP_DEBUG']);
        $this->assertSame('pgsql', $host['DB_CONNECTION']);
        $this->assertSame('owned_lab', $host['LAB_DB_DATABASE']);
        $this->assertSame('owned-db-host', $host['DB_HOST']);
        $this->assertSame('owned-user', $host['DB_USERNAME']);
        $this->assertSame('synthetic-host-db-password', $host['DB_PASSWORD']);
        $this->assertSame('', $host['DB_URL']);
        $this->assertArrayNotHasKey('DB_DATABASE', $host);
        $this->assertSame('owned_sandbox', $host['LAB_SANDBOX_DB_DATABASE']);
        $this->assertSame('synthetic-host-app-key', $host['APP_KEY']);
        $this->assertSame('91', $host['LAB_OWNER_USER_ID']);
        $this->assertSame('synthetic-host-provider-key', $host['ANTHROPIC_API_KEY']);
        $this->assertSame('1900', $host['DB_QUEUE_RETRY_AFTER']);
        $this->assertSame('(null)', $host['DB_QUEUE_CONNECTION']);
        $this->assertSame('owned-claude-home', $host['CLAUDE_CONFIG_DIR']);
        $this->assertSame('owned-codex-home', $host['CODEX_HOME']);
        $this->assertSame('/owned/claude-fixture.php', $host['LAB_ACP_CLAUDE_ARGS']);
        $this->assertSame('/owned/workspace', $host['LAB_ACP_WORKSPACE']);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $host);
        $this->assertArrayNotHasKey('NODE_OPTIONS', $host);
        $this->assertStringNotContainsString('private-ambient', json_encode($host));
    }

    public function test_host_only_names_and_values_are_removed_by_the_native_child_environment_contract(): void
    {
        $this->hostConfiguration();
        $host = HostEnvironment::build(['PATH' => 'owned-path']);
        $child = ChildEnv::build($host, ['CLAUDE_CONFIG_DIR', 'CODEX_HOME', 'CODEX_CONFIG_DIR', 'XDG_CONFIG_HOME'])->env;
        $this->assertSame(['PATH' => 'owned-path'], $child);
        foreach (['synthetic-host-app-key', 'synthetic-host-db-password', 'synthetic-host-provider-key', 'owned_lab'] as $canary) {
            $this->assertStringNotContainsString($canary, json_encode($child));
        }
    }

    public static function cacheSelectors(): array
    {
        return [['APP_CONFIG_CACHE'], ['APP_SERVICES_CACHE'], ['APP_PACKAGES_CACHE']];
    }

    #[DataProvider('cacheSelectors')]
    public function test_normal_relative_cache_selector_is_preserved_and_not_taken_from_the_parent_argument(string $selector): void
    {
        $this->hostConfiguration();
        $repository = Env::getRepository();
        $this->assertNull(Env::get($selector));
        $this->assertTrue($repository->set($selector, 'storage/framework/testing/owned-native-config.php'));
        try {
            $host = HostEnvironment::build([$selector => 'unrelated-config.php']);
            $this->assertArrayHasKey($selector, $host);
            $this->assertSame('storage/framework/testing/owned-native-config.php', $host[$selector]);
            $this->assertArrayNotHasKey($selector, ChildEnv::build($host)->env);
        } finally {
            $repository->clear($selector);
        }
    }

    public function test_cached_configuration_byte_changes_change_the_frozen_runtime_scope(): void
    {
        $this->hostConfiguration();
        $directory = storage_path('framework/testing/acp-cache-'.bin2hex(random_bytes(8)));
        $this->assertDirectoryDoesNotExist($directory);
        mkdir($directory, 0700, true);
        $cache = $directory.'/config.php';
        file_put_contents($cache, '<?php return [];');
        $repository = Env::getRepository();
        $this->assertNull(Env::get('APP_CONFIG_CACHE'));
        $selector = 'storage/framework/testing/'.basename($directory).'/config.php';
        $this->assertTrue($repository->set('APP_CONFIG_CACHE', $selector));
        $before = app()->make('config_loaded_from_cache');
        app()->instance('config_loaded_from_cache', true);
        try {
            $first = app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', ['prompt' => 'owned prompt', 'model' => 'haiku']);
            file_put_contents($cache, "\n// owned cache changed\n", FILE_APPEND);
            $second = app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', ['prompt' => 'owned prompt', 'model' => 'haiku']);
            $this->assertNotSame($first->payload['runtime']['source_digest'], $second->payload['runtime']['source_digest']);
        } finally {
            $repository->clear('APP_CONFIG_CACHE');
            app()->instance('config_loaded_from_cache', $before);
            unlink($cache);
            rmdir($directory);
        }
    }

    public static function unavailableHost(): array
    {
        return ['memory is not shared' => ['memory'], 'namespace mismatch' => ['namespace'],
            'driver mismatch' => ['driver'], 'missing app key' => ['key'], 'missing owner' => ['owner'],
            'missing provider key' => ['provider-key'], 'nonlocal' => ['environment'], 'argv loses quoting' => ['argv']];
    }

    public static function nativeDrivers(): array
    {
        return ['Claude' => ['claude'], 'Codex' => ['codex']];
    }

    #[DataProvider('nativeDrivers')]
    public function test_actual_native_cli_does_not_receive_host_only_names_or_synthetic_values(string $provider): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('This native ACP containment measurement runs on Windows.');
        }
        $this->hostConfiguration();
        $workspace = storage_path('framework/testing/acp-env-'.bin2hex(random_bytes(8)));
        $this->assertDirectoryDoesNotExist($workspace);
        mkdir($workspace, 0700, true);
        $this->beforeApplicationDestroyed(function () use ($workspace): void {
            $root = realpath(storage_path('framework/testing'));
            $path = realpath($workspace);
            $this->assertNotFalse($root);
            $this->assertNotFalse($path);
            $this->assertStringStartsWith(str_replace('\\', '/', $root).'/', str_replace('\\', '/', $path));
            $this->assertFalse(is_link($workspace));
            app(Filesystem::class)->deleteDirectory($workspace);
        });
        $host = HostEnvironment::build(getenv());
        $this->assertSame('synthetic-host-app-key', $host['APP_KEY']);
        $this->assertSame('synthetic-host-db-password', $host['DB_PASSWORD']);
        $this->assertSame('synthetic-host-provider-key', $host['ANTHROPIC_API_KEY']);
        $updates = [];
        $ended = false;
        $events = new DriverEvents(
            onUpdate: function (array $update) use (&$updates): void {
                $updates[] = $update;
            },
            onTurnEnd: function () use (&$ended): void {
                $ended = true;
            },
        );
        $loop = Loop::get();
        $driver = $provider === 'claude'
            ? new ClaudeDriver(new ClaudeDriverOptions(cwd: $workspace, binary: PHP_BINARY,
                binaryArgs: [base_path('tests/fixtures/acp/fake-claude.php')], parentEnv: $host,
                permissionMode: 'dontAsk', permissionPrompts: ['*'], model: 'haiku'), $events, $loop)
            : new CodexDriver(new CodexDriverOptions(cwd: $workspace, binary: PHP_BINARY,
                binaryArgs: [base_path('tests/fixtures/acp/fake-codex.php'), ...ConversationCodexTransport::arguments()],
                parentEnv: $host, model: 'fixture-model', approvalPolicy: 'untrusted', sandbox: 'read-only'), $events,
                fn (CodexTransportOptions $options, CodexTransportHandlers $handlers) => new ConversationCodexTransport($options, $handlers), $loop);
        try {
            $driver->start();
            $driver->modelState()->await();
            $driver->prompt('env-canaries');
            $deadline = microtime(true) + 10;
            $loop->run(function () use (&$ended, $deadline): bool {
                return $ended || microtime(true) > $deadline;
            });
            $this->assertTrue($ended, 'The owned native CLI did not finish its canary prompt.');
            $text = collect($updates)->where('sessionUpdate', 'agent_message_chunk')->map(fn (array $update) => $update['content']['text'])->implode('');
            $observed = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([], $observed['hostNames']);
            $this->assertTrue($observed['pathPresent']);
            $this->assertTrue($observed['syntheticValuesAbsent']);
        } finally {
            $verdict = $driver->stop(3000)->await();
            $this->assertTrue($verdict->verified);
            $this->assertTrue($verdict->empty);
        }
    }

    #[DataProvider('unavailableHost')]
    public function test_unavailable_or_unrepresentable_host_configuration_refuses_before_launch(string $change): void
    {
        $this->hostConfiguration();
        match ($change) {
            'memory' => config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'lab-database.names.sqlite' => ':memory:']),
            'namespace' => config(['lab-database.names.pgsql' => 'another-database']),
            'driver' => config(['database.connections.pgsql.driver' => 'mysql']),
            'key' => config(['app.key' => '']),
            'owner' => config(['lab_authority.owner_user_id' => null]),
            'provider-key' => config(['prism.providers.anthropic.api_key' => '']),
            'environment' => $this->app->detectEnvironment(fn () => 'production'),
            'argv' => config(['acp.claude.binary_args' => ['path with spaces.php']]),
        };
        $this->expectException(PaidRunNotAuthorised::class);
        HostEnvironment::build([]);
    }

    private function hostConfiguration(): void
    {
        // Pure config projection only. Never resolve or query this connection.
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'database.default' => 'pgsql', 'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.database' => 'owned_lab', 'lab-database.names.pgsql' => 'owned_lab',
            'database.connections.pgsql.host' => 'owned-db-host', 'database.connections.pgsql.username' => 'owned-user',
            'database.connections.pgsql.password' => 'synthetic-host-db-password',
            'lab-database.sandbox_database' => 'owned_sandbox', 'app.key' => 'synthetic-host-app-key',
            'lab_authority.owner_user_id' => 91, 'queue.connections.database.retry_after' => 1900,
            'team.coordinator.provider' => 'anthropic', 'prism.providers.anthropic.api_key' => 'synthetic-host-provider-key',
            'acp.claude.binary_args' => ['/owned/claude-fixture.php'], 'acp.codex.binary_args' => [],
            'acp.workspace' => '/owned/workspace',
        ]);
    }
}
