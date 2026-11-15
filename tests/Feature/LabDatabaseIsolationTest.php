<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\LabDatabase;
use Closure;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class LabDatabaseIsolationTest extends TestCase
{
    public static function drivers(): array
    {
        return array_combine(['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'], array_map(
            static fn (string $driver): array => [$driver],
            ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'],
        ));
    }

    #[DataProvider('drivers')]
    public function test_every_adapter_uses_only_the_lab_namespace(string $driver): void
    {
        $this->withEnvironment([
            'LAB_DB_DATABASE' => 'lab_owned', 'DB_DATABASE' => 'workspace_shared',
            'DB_URL' => 'pgsql://dummy-user:dummy-password@127.0.0.1:9/workspace_shared',
        ], function () use ($driver): void {
            $configuration = require base_path('config/database.php');
            $this->assertSame('lab_owned', $configuration['connections'][$driver]['database']);
            $this->assertNull($configuration['connections'][$driver]['url']);
        });
    }

    public function test_dedicated_defaults_and_the_zero_string_are_preserved(): void
    {
        $this->withEnvironment(['LAB_DB_DATABASE' => null, 'APP_ENV' => 'testing'], function (): void {
            $this->assertSame(':memory:', LabDatabase::name('sqlite'));
            foreach (['mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
                $this->assertSame('prism_labs', LabDatabase::name($driver));
            }
        });
        $this->withEnvironment(['LAB_DB_DATABASE' => null, 'APP_ENV' => 'local'], function (): void {
            $this->assertSame(database_path('database.sqlite'), LabDatabase::name('sqlite'));
        });
        $this->withEnvironment(['LAB_DB_DATABASE' => '0'], function (): void {
            $this->assertSame('0', LabDatabase::name('pgsql'));
        });
    }

    public static function malformedNames(): array
    {
        return ['empty' => [''], 'whitespace' => ['  '], 'boolean-true' => ['true'], 'boolean-false' => ['false']];
    }

    #[DataProvider('malformedNames')]
    public function test_malformed_names_are_refused_without_echoing_configuration(string $name): void
    {
        $this->withEnvironment(['LAB_DB_DATABASE' => $name], function (): void {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(LabDatabase::ISOLATION_ERROR);
            LabDatabase::name('pgsql');
        });
    }

    public function test_uncached_runtime_boot_ignores_the_workspace_name_and_url_before_providers(): void
    {
        $result = $this->probe(['DB_CONNECTION' => 'pgsql', 'LAB_DB_DATABASE' => 'lab_owned']);
        $this->assertSame(['loaded' => true, 'noProvidersRegistered' => true, 'noDatabaseManager' => true], $result);
    }

    public function test_uncached_sqlite_defaults_to_its_owned_file(): void
    {
        $this->assertTrue($this->probe(['DB_CONNECTION' => 'sqlite', 'LAB_DB_DATABASE' => false])['loaded']);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'shared-server' => ['shared'],
            'case-folded-server' => ['case-folded'],
            'cached-url' => ['url'],
            'missing-marker' => ['marker'],
            'alternate-named-route' => ['alternate'],
            'read-database' => ['read'],
            'write-url' => ['write'],
            'random-read-endpoint' => ['read-list'],
            'direct-database' => ['direct'],
            'pooled-database-override' => ['pooled'],
            'sandbox-sqlite' => ['sqlite'],
            'sandbox-sqlite-traversal' => ['traversal'],
            'sandbox-sqlite-case' => ['sqlite-case'],
            'sqlite-uri' => ['uri'],
            'test-file-database' => ['test-file'],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_native_boot_refuses_unsafe_cached_configuration_before_providers(string $case): void
    {
        $cache = $this->cachedConfiguration();
        switch ($case) {
            case 'shared':
            case 'case-folded':
                $name = $case === 'shared' ? 'workspace_shared' : 'WORKSPACE_SHARED';
                $cache['database']['connections']['pgsql']['database'] = $name;
                $cache['lab-database']['names']['pgsql'] = $name;
                break;
            case 'url':
                $cache['database']['connections']['pgsql']['url'] = 'pgsql://dummy-user:dummy-password@127.0.0.1:9/workspace_shared';
                break;
            case 'marker':
                unset($cache['lab-database']);
                break;
            case 'alternate':
                $cache['database']['connections']['alternate'] = ['driver' => 'pgsql', 'database' => 'workspace_shared'];
                break;
            case 'read':
                $cache['database']['connections']['pgsql']['read'] = ['database' => 'workspace_shared'];
                break;
            case 'write':
                $cache['database']['connections']['pgsql']['write'] = ['url' => 'pgsql://dummy-user:dummy-password@127.0.0.1:9/workspace_shared'];
                break;
            case 'read-list':
                $cache['database']['connections']['pgsql']['read'] = [['host' => '127.0.0.1'], ['database' => 'workspace_shared']];
                break;
            case 'direct':
                $cache['database']['connections']['pgsql']['direct'] = ['database' => 'workspace_shared'];
                break;
            case 'pooled':
                $cache['database']['connections']['pgsql']['connect_via_database'] = 'workspace_shared';
                break;
            case 'sqlite':
            case 'traversal':
            case 'sqlite-case':
            case 'uri':
            case 'test-file':
                $name = match ($case) {
                    'traversal' => base_path('../prism-sandbox/database/../database/database.sqlite'),
                    'sqlite-case' => PHP_OS_FAMILY === 'Windows'
                        ? strtoupper(base_path('../prism-sandbox/database/database.sqlite'))
                        : base_path('../prism-sandbox/database/database.sqlite'),
                    'uri' => 'file:owned.sqlite?mode=memory',
                    'test-file' => storage_path('framework/testing/not-memory.sqlite'),
                    default => base_path('../prism-sandbox/database/database.sqlite'),
                };
                $cache['database'] = ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $name]]];
                $cache['lab-database']['names']['sqlite'] = $name;
                if ($case === 'test-file') {
                    $cache['app']['env'] = 'testing';
                }
                break;
        }
        $result = $this->probe([], $cache);
        $this->assertSame(['loaded' => false, 'error' => $case === 'test-file'
            ? LabDatabase::TEST_DATABASE_ERROR : LabDatabase::ISOLATION_ERROR], $result);
    }

    public function test_cached_dedicated_namespace_can_boot_without_reading_dotenv(): void
    {
        $this->assertSame(['loaded' => true, 'noProvidersRegistered' => true, 'noDatabaseManager' => true], $this->probe([], $this->cachedConfiguration()));
    }

    public function test_real_memory_queries_and_late_named_memory_connections_work(): void
    {
        $this->app['db']->statement('create table isolation_positive (value text)');
        $this->app['db']->insert('insert into isolation_positive values (?)', ['memory only']);
        $this->assertSame('memory only', $this->app['db']->selectOne('select value from isolation_positive')->value);
        config(['database.connections.named_memory' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $this->assertSame(42, $this->app['db']->connection('named_memory')->selectOne('select 42 as value')->value);
        $dynamic = $this->app['db']->build(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->assertSame(7, $dynamic->selectOne('select 7 as value')->value);
    }

    public function test_a_refused_named_connection_cannot_be_recovered_from_the_manager_cache(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lab-isolation-');
        try {
            config(['database.connections.owned_negative' => ['driver' => 'sqlite', 'database' => $file]]);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $this->app['db']->connection('owned_negative')->statement('create table forbidden_write (value text)');
                    $this->fail('A file database was reachable from the test application.');
                } catch (RuntimeException $exception) {
                    $this->assertSame(LabDatabase::TEST_DATABASE_ERROR, $exception->getMessage());
                }
                $this->assertArrayNotHasKey('owned_negative', $this->app['db']->getConnections());
            }
            // Independent PDO reaches only this test-owned temporary file.
            $pdo = new PDO('sqlite:'.$file);
            $this->assertSame(0, (int) $pdo->query("select count(*) from sqlite_master where name = 'forbidden_write'")->fetchColumn());
            $pdo = null;
        } finally {
            $this->app['db']->purge('owned_negative');
            unlink($file);
        }
    }

    public function test_the_query_guard_rechecks_an_existing_connection_even_after_environment_changes(): void
    {
        $connection = $this->app['db']->connection();
        $this->assertSame(1, $connection->selectOne('select 1 as value')->value);
        $connection->setDatabaseName('not-memory');
        $this->app->instance('env', 'local');
        try {
            $connection->selectOne('select 2 as value');
            $this->fail('An existing connection bypassed the query guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame(LabDatabase::TEST_DATABASE_ERROR, $exception->getMessage());
        }
        $this->assertArrayNotHasKey('sqlite', $this->app['db']->getConnections());
        $replacement = $this->app['db']->connection();
        $this->assertNotSame($connection, $replacement);
        $this->assertSame(3, $replacement->selectOne('select 3 as value')->value);
    }

    public static function connectionModes(): array
    {
        return ['read' => ['::read'], 'write' => ['::write'], 'direct' => ['::direct'], 'dynamic' => ['dynamic']];
    }

    #[DataProvider('connectionModes')]
    public function test_late_connections_refuse_before_the_factory_can_open_pdo(string $mode): void
    {
        // A missing owned path would produce a connector error if PDO was
        // attempted. Only the fixed isolation error is an acceptable refusal.
        $config = ['driver' => 'sqlite', 'database' => storage_path('framework/testing/must-not-open.sqlite')];
        config(['database.connections.late_unsafe' => $config]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(LabDatabase::TEST_DATABASE_ERROR);
        $mode === 'dynamic' ? $this->app['db']->build($config) : $this->app['db']->connection('late_unsafe'.$mode);
    }

    private function cachedConfiguration(): array
    {
        return [
            'app' => ['env' => 'local', 'timezone' => 'UTC'],
            'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => [
                'driver' => 'pgsql', 'database' => 'lab_owned', 'url' => null,
                'host' => '127.0.0.1', 'port' => 9, 'username' => 'dummy-user', 'password' => 'dummy-password',
            ]]],
            'lab-database' => ['version' => 1, 'names' => ['pgsql' => 'lab_owned'],
                'sandbox_database' => 'workspace_shared', 'sandbox_sqlite' => base_path('../prism-sandbox/database/database.sqlite')],
        ];
    }

    private function probe(array $overrides = [], ?array $cache = null): array
    {
        // Process otherwise inherits the parent. Remove every variable first;
        // forward only the OS essentials and synthetic, uncredentialed inputs.
        $environment = array_fill_keys(array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]), false);
        foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
            if (getenv($name) !== false) {
                $environment[$name] = getenv($name);
            }
        }
        $cacheDirectory = storage_path('framework/testing/database-isolation');
        if (! is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0700, true);
        }
        $cacheFile = tempnam($cacheDirectory, 'lab-config-');
        try {
            if ($cache !== null) {
                file_put_contents($cacheFile, '<?php return '.var_export($cache, true).';');
            } else {
                unlink($cacheFile);
            }
            $environment = array_replace($environment, [
                'APP_ENV' => 'local', 'APP_CONFIG_CACHE' => 'storage/framework/testing/database-isolation/'.basename($cacheFile),
                'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'workspace_shared',
                'DB_URL' => 'pgsql://dummy-user:dummy-password@127.0.0.1:9/workspace_shared',
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '9', 'DB_USERNAME' => 'dummy-user',
                'DB_PASSWORD' => 'dummy-password', 'LAB_DB_DATABASE' => 'lab_owned',
            ], $overrides);
            $process = new Process([PHP_BINARY, base_path('tests/fixtures/database-bootstrap.php')], base_path(), $environment, timeout: 15);
            $process->mustRun();
            $this->assertSame('', $process->getErrorOutput());
            $output = $process->getOutput();
            foreach (['dummy-user', 'dummy-password', 'pgsql://', 'workspace_shared'] as $private) {
                $this->assertStringNotContainsString($private, $output);
            }

            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($cache !== null, $result['cached'], 'The native probe must exercise the requested cache lane.');
            unset($result['cached']);

            return $result;
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    private function withEnvironment(array $values, Closure $callback): void
    {
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = [getenv($name), array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null,
                array_key_exists($name, $_ENV), $_ENV[$name] ?? null];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
            if ($value !== null) {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv($name.'='.$value);
            }
        }
        try {
            $callback();
        } finally {
            foreach ($original as $name => [$value, $hasServer, $server, $hasEnv, $env]) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
                unset($_SERVER[$name], $_ENV[$name]);
                if ($hasServer) {
                    $_SERVER[$name] = $server;
                }
                if ($hasEnv) {
                    $_ENV[$name] = $env;
                }
            }
        }
    }
}
