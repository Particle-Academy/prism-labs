<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual database queue workers, Flow executor, Session and production observer. */
final class NativeRsiCostProcessTest extends TestCase
{
    private string $directory;

    private array $children = [];

    private array $workers = [];

    private int $tag = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        $parent = storage_path('framework/testing');
        if (! is_dir($parent)) {
            mkdir($parent, 0700, true);
        }
        $candidate = $parent.'/native-authority-'.bin2hex(random_bytes(8));
        $this->assertDirectoryDoesNotExist($candidate);
        mkdir($candidate, 0700);
        $this->directory = realpath($candidate);
        file_put_contents($this->directory.'/fixture.marker', "G246 native authority fixture v1\n");
        touch($this->directory.'/fixture.env');
        touch($this->directory.'/lab.sqlite');
        $this->assertTrue($this->fixture('prepare', 'rsi-cost')['prepared']);
        $this->assertFalse($this->fixture('ready')['ready']); // Warm final service manifest before competing workers.
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->directory)) {
                $retained = storage_path('framework/testing/rsi-cost-process-logs/'.basename($this->directory));
                app(Filesystem::class)->ensureDirectoryExists($retained, 0700, true);
                foreach (glob($this->directory.'/*') as $file) {
                    if (is_file($file) && preg_match('/\.(?:stdout|stderr|json|log)\z/', $file)) {
                        copy($file, $retained.'/'.basename($file));
                    }
                }
                // Only owned handles; release barriers even when an assertion fails.
                file_put_contents($this->directory.'/rsi-release', 'release');
                $this->invoke(['artisan', 'queue:restart']);
                foreach ($this->workers as $key) {
                    if (is_resource($this->children[$key]['process'])) {
                        $this->finish($key, 12);
                    }
                }
            }
        } finally {
            foreach ($this->children as $child) {
                if (is_resource($child['process'])) {
                    proc_terminate($child['process']);
                    proc_close($child['process']);
                }
            }
            if (isset($this->directory)) {
                $this->assertSame(realpath(storage_path('framework/testing')), dirname(realpath($this->directory)));
                $this->assertFalse(is_link($this->directory));
                app(Filesystem::class)->deleteDirectory($this->directory);
            }
            parent::tearDown();
        }
    }

    public static function outcomes(): array
    {
        return [['settled'], ['killed']];
    }

    #[DataProvider('outcomes')]
    public function test_two_actual_queue_workers_share_committed_native_reservations_and_never_refund_uncertainty(string $outcome): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->workers[] = $this->start(['artisan', 'queue:work', 'database', '--queue=default,scoring,overseer,commentary', '--sleep=1', '--timeout=1800', '--max-time=60', '--tries=1']);
        }
        $deadline = microtime(true) + 20;
        while (! $this->fixture('ready')['ready'] || count(glob($this->directory.'/workers/*.json')) < 2) {
            $this->assertLessThan($deadline, microtime(true), 'Owned workers did not become ready.');
            usleep(50000);
        }
        $seed = $this->fixture('seed-rsi-cost');
        $this->assertCount(2, $seed['lanes']);
        $deadline = microtime(true) + 20;
        $state = null;
        do {
            if (is_file($this->directory.'/rsi-provider-entered.json')) {
                $state = $this->fixture('inspect-rsi-cost');
                $refusals = array_filter($state['lanes'], fn ($lane) => ($lane['proof']['code'] ?? null) === 'account_exhausted');
                if (count($refusals) === 1) {
                    break;
                }
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertNotNull($state, 'No actual native provider entry was observed.');
        $this->assertCount(1, $refusals ?? [], json_encode($state));
        $this->assertCount(1, $state['calls']);
        $this->assertSame(600000, (int) $state['calls'][0]['maximum_microusd']);
        $this->assertNull($state['calls'][0]['settled_at']);
        $this->assertNull($state['calls'][0]['charged_microusd']);
        $entry = json_decode(file_get_contents($this->directory.'/rsi-provider-entered.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $entry['calls']);
        $this->assertSame($state['calls'][0]['call_id'], $entry['call_id']);
        $workerPids = array_map(fn ($key) => proc_get_status($this->children[$key]['process'])['pid'], $this->workers);
        $this->assertCount(2, array_unique($workerPids));
        $this->assertContains($entry['pid'], $workerPids);
        if ($outcome === 'killed') {
            $key = $this->workers[array_search($entry['pid'], $workerPids, true)];
            $this->assertTrue(proc_terminate($this->children[$key]['process']));
            $this->assertNotSame(0, $this->finish($key, 5));
            $after = $this->fixture('inspect-rsi-cost');
            $this->assertCount(1, $after['calls']);
            $this->assertNull($after['calls'][0]['settled_at']);
            $this->assertNull($after['calls'][0]['charged_microusd']);
        } else {
            file_put_contents($this->directory.'/rsi-release', 'release');
            $deadline = microtime(true) + 12;
            do {
                $after = $this->fixture('inspect-rsi-cost');
                if ($after['calls'][0]['settled_at'] !== null) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertNotNull($after['calls'][0]['settled_at']);
            $this->assertSame(40000, (int) $after['calls'][0]['charged_microusd']);
            $this->assertTrue((bool) $after['calls'][0]['usage_known']);
            $deadline = microtime(true) + 5;
            do {
                $after = $this->fixture('inspect-rsi-cost');
                if (count(array_filter($after['lanes'], fn ($lane) => $lane['status'] === 'running')) === 0) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $statuses = array_column($after['lanes'], 'status');
            sort($statuses);
            $this->assertSame(['completed', 'failed'], $statuses, json_encode($after));
        }
        $this->assertSame(0, $after['judgments']);
    }

    private function fixture(string $mode, string $case = ''): array
    {
        $result = $this->invoke(['tests/fixtures/authority/process.php', $this->directory, $mode, $case]);

        return json_decode(trim($result), true, flags: JSON_THROW_ON_ERROR);
    }

    private function invoke(array $args): string
    {
        $key = $this->start($args);
        $this->assertSame(0, $this->finish($key, 30), file_get_contents($this->children[$key]['stderr']).file_get_contents($this->children[$key]['stdout']));

        return file_get_contents($this->children[$key]['stdout']);
    }

    private function start(array $args): int
    {
        $tag = 'rsi-cost-'.(++$this->tag);
        $stdout = $this->directory.'/'.$tag.'.stdout';
        $stderr = $this->directory.'/'.$tag.'.stderr';
        $env = [];
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }
        $env = [...$env, 'APP_ENV' => 'g246-native-fixture', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'OTEL_PHP_DETECTORS' => 'none',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'LAB_DB_DATABASE' => $this->directory.'/lab.sqlite',
            'LAB_OWNER_USER_ID' => '1', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array',
            'APP_CONFIG_CACHE' => 'storage/framework/testing/'.basename($this->directory).'/config.php',
            'APP_SERVICES_CACHE' => 'storage/framework/testing/'.basename($this->directory).'/services.php',
            'APP_PACKAGES_CACHE' => 'storage/framework/testing/'.basename($this->directory).'/packages.php',
            'LAB_WORKER_HEARTBEATS_DIRECTORY' => $this->directory.'/workers',
            'G246_NATIVE_AUTHORITY_DIRECTORY' => $this->directory, 'G246_AUTHORITY_OBSERVATION_TAG' => $tag];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', ...$args],
            [['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], ['file', $stdout, 'w'], ['file', $stderr, 'w']],
            $pipes, base_path(), $env, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        $this->children[] = ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];

        return array_key_last($this->children);
    }

    private function finish(int $key, int $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($this->children[$key]['process']);
            if (! $status['running']) {
                proc_close($this->children[$key]['process']);
                $this->children[$key]['process'] = null;

                return $status['exitcode'];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        proc_terminate($this->children[$key]['process']);
        proc_close($this->children[$key]['process']);
        $this->children[$key]['process'] = null;
        $this->fail('Owned native cost process exceeded its deadline.');
    }
}
