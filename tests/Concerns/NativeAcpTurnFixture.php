<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\AcpTurn;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Tests\TestCase;

/** Normal cached PHP hosts and real queue workers, with owned file SQLite. */
final class NativeAcpTurnFixture
{
    public readonly string $path;

    private array $environment;

    private array $processes = [];

    private ?array $idle = null;

    private bool $closed = false;

    public function __construct(private readonly TestCase $test, private readonly string $provider, string $variant = 'normal')
    {
        $test->assertContains($provider, ['claude', 'codex']);
        $test->assertContains($variant, ['normal', 'slow-history', 'invalid-config']);
        $this->path = str_replace('\\', '/', storage_path('framework/testing/acp-native-'.bin2hex(random_bytes(8))));
        $test->assertDirectoryDoesNotExist($this->path);
        mkdir($this->path, 0700, true);
        file_put_contents($this->path.'/.g246-native-acp', "owned-native-acp-fixture\n", LOCK_EX);
        file_put_contents($this->path.'/empty.env', '', LOCK_EX);
        file_put_contents($this->path.'/lab.sqlite', '', LOCK_EX);
        self::directory($this->path);
        $this->environment = [];
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $this->environment[$key] = $value;
            }
        }
        $relative = 'storage/framework/testing/'.basename($this->path);
        $codexArgs = str_replace('\\', '/', base_path('tests/fixtures/acp/fake-codex.php'));
        if ($variant !== 'normal') {
            $codexArgs .= $variant === 'slow-history' ? ' --fixture-slow-history' : ' --fixture-invalid-config';
        }
        $this->environment = [...$this->environment,
            'APP_ENV' => 'g246-native-fixture', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'APP_CONFIG_CACHE' => $relative.'/config.php', 'APP_SERVICES_CACHE' => $relative.'/services.php',
            'APP_PACKAGES_CACHE' => $relative.'/packages.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'LAB_DB_DATABASE' => $this->path.'/lab.sqlite', 'LAB_SANDBOX_DB_DATABASE' => ':memory:',
            'LAB_OWNER_USER_ID' => '1', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_RETRY_AFTER' => '1900',
            'LAB_WORKER_HEARTBEATS_DIRECTORY' => $this->path.'/workers',
            'PRISM_COORDINATOR_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => 'synthetic-host-provider-key',
            'PRISM_LEARNINGS_PATH' => $this->path.'/learnings', 'LAB_ACP_WORKSPACE' => $this->path.'/workspace',
            'LAB_ACP_CLAUDE_BINARY' => PHP_BINARY,
            'LAB_ACP_CLAUDE_ARGS' => str_replace('\\', '/', base_path('tests/fixtures/acp/fake-claude.php')),
            'LAB_ACP_MODEL' => 'haiku', 'LAB_ACP_CODEX_BINARY' => PHP_BINARY, 'LAB_ACP_CODEX_ARGS' => $codexArgs,
            'LAB_ACP_CODEX_MODEL' => 'fixture-model', 'LAB_ACP_TURN_SECONDS' => '2',
            'LAB_ACP_SESSION_SECONDS' => $variant === 'slow-history' ? '2' : '10',
        ];
    }

    public function prepare(): void
    {
        $this->fixture('prepare');
        $this->idle = $this->start(['artisan', 'queue:work', 'database', '--queue=default,scoring',
            '--sleep=1', '--timeout=1800', '--max-time=60', '--tries=1'], 'idle');
        $deadline = microtime(true) + 8;
        do {
            foreach (glob($this->path.'/workers/*.json') ?: [] as $file) {
                $record = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (($record['state'] ?? null) === 'idle') {
                    return; // Native enqueue still verifies signature, age and exact readiness.
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->test->fail('The owned native idle worker never became available. '.$this->diagnostic($this->idle));
    }

    public static function directory(string $directory): string
    {
        $parent = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 2).'/storage/framework/testing'));
        $resolved = str_replace('\\', '/', (string) realpath($directory));
        if ($parent === '' || $resolved === '' || $resolved !== str_replace('\\', '/', $directory)
            || ! str_starts_with($resolved, $parent.'/') || ! preg_match('/^acp-native-[a-f0-9]{16}$/D', basename($resolved))
            || is_link($directory) || ! is_file($directory.'/.g246-native-acp')
            || file_get_contents($directory.'/.g246-native-acp') !== "owned-native-acp-fixture\n") {
            throw new RuntimeException('Refuse non-owned native ACP fixture directory.');
        }

        return $resolved;
    }

    public function turn(string $step): AcpTurn
    {
        $this->test->assertContains($step, ['hello', 'one', 'two', 'hook', 'approval', 'invalid-config']);
        $queued = $this->fixture('enqueue', $step);
        $worker = $this->start(['artisan', 'queue:work', 'database', '--queue=overseer', '--sleep=1',
            '--timeout=1800', '--max-jobs=1', '--max-time=30', '--tries=1'], 'worker-'.$step);
        $this->test->assertSame(0, $this->finish($worker, 35), $this->diagnostic($worker));
        $result = $this->fixture('inspect', $step);
        $this->test->assertSame($queued['turn_id'], $result['attributes']['id']);
        $this->test->assertTrue($result['native_job_consumed']);
        $this->test->assertSame(0, $result['failed_jobs']);
        $this->test->assertTrue($result['work_claimed']);
        $this->test->assertTrue($result['inspector_guest']);
        $this->test->assertTrue($result['exporter_closed']);
        $this->test->assertTrue($result['host_claimed']);
        $hosts = glob($this->path.'/host-env-*.json') ?: [];
        $this->test->assertNotEmpty($hosts, 'A real normal PHP ACP host must report.');
        foreach ($hosts as $file) {
            foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $name => $value) {
                $this->test->assertTrue($value, 'A native host or child environment observation failed: '.$name);
            }
        }
        $workers = glob($this->path.'/worker-env-*.json') ?: [];
        $this->test->assertNotEmpty($workers, 'A real normal queue worker must report.');
        foreach ($workers as $file) {
            foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $name => $value) {
                $this->test->assertTrue($value, 'The actual worker must remain a guest in its isolated cached host: '.$name);
            }
        }

        // Hydrate only attributes actually read by the normal inspector; no
        // parent in-memory row can pretend to be the worker's durable record.
        return (new AcpTurn)->newFromBuilder($result['attributes']);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            if ($this->idle !== null && is_resource($this->idle['process'])) {
                $this->fixture('stop');
                $this->test->assertSame(0, $this->finish($this->idle, 10), $this->diagnostic($this->idle));
                $this->test->assertSame([], glob($this->path.'/workers/*.json') ?: [], 'Native stop must remove its own heartbeat.');
            }
        } finally {
            foreach ($this->processes as $process) {
                if (is_resource($process)) {
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process);
                    }
                    proc_close($process);
                }
            }
            self::directory($this->path);
            (new Filesystem)->deleteDirectory($this->path);
        }
    }

    private function fixture(string $mode, string $step = 'hello'): array
    {
        $child = $this->start(['tests/fixtures/acp/normal-host.php', $this->path, $mode, $this->provider, $step], $mode.'-'.$step);
        $this->test->assertSame(0, $this->finish($child, 30), $this->diagnostic($child));

        return json_decode(trim(file_get_contents($child['stdout'])), true, flags: JSON_THROW_ON_ERROR);
    }

    private function start(array $arguments, string $tag): array
    {
        self::directory($this->path);
        $stdout = $this->path.'/'.$tag.'.stdout';
        $stderr = $this->path.'/'.$tag.'.stderr';
        $this->test->assertFileDoesNotExist($stdout);
        if ($arguments[0] === 'artisan') {
            $this->test->assertFileExists($this->path.'/config.php');
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', ...$arguments],
            [['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], ['file', $stdout, 'w'], ['file', $stderr, 'w']],
            $pipes, base_path(), $this->environment, ['bypass_shell' => true]);
        $this->test->assertIsResource($process);
        $this->processes[] = $process;

        return ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function finish(array &$child, int $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                proc_close($child['process']);
                $child['process'] = null;

                return $exit;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        proc_terminate($child['process']);
        proc_close($child['process']);
        $child['process'] = null;
        $this->test->fail('An owned native ACP process exceeded its deadline.');
    }

    private function diagnostic(array $child): string
    {
        return mb_substr(file_get_contents($child['stdout']).file_get_contents($child['stderr']), 0, 3000);
    }
}
