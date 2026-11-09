<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\NativeAuthorityFixture;
use Tests\TestCase;

/** Actual Artisan binaries and competing PHP processes; no real provider or DB. */
final class NativeRunAuthorityProcessTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    private array $processes = [];

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
        $result = $this->fixture('prepare', 'prepare');
        $this->assertSame(['prepared' => true, 'owner' => 1], $result);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->workers !== []) {
                $this->invokeNative(['artisan', 'queue:restart'], 'stop-workers');
                foreach ($this->workers as &$worker) {
                    $this->assertSame(0, $this->finish($worker, 10));
                }
                unset($worker);
            }
        } finally {
            foreach ($this->processes as $process) {
                if (is_resource($process)) {
                    // Only a handle this fixture started; no PID lookup or broad kill.
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if (isset($this->directory)) {
                $parent = realpath(storage_path('framework/testing'));
                $this->assertSame($parent, dirname(realpath($this->directory)));
                $this->assertFalse(is_link($this->directory));
                app(Filesystem::class)->deleteDirectory($this->directory);
            }
            parent::tearDown();
        }
    }

    public static function commands(): array
    {
        return array_map(fn (string $case): array => [$case], ['team', 'tests', 'reservation', 'recall', 'summary']);
    }

    #[DataProvider('commands')]
    public function test_real_cli_requires_exact_go_reaches_the_sdk_and_refuses_replay(string $case): void
    {
        $this->startWorkers(1);
        $seed = $this->fixture('seed-cli', 'seed-cli', $case);
        $id = $seed['ids'][0];
        $spec = NativeAuthorityFixture::cli($case);
        $changed = $this->invokeNative(['artisan', ...$spec['changed'], '--approved-run='.$id], 'changed');
        $this->assertSame(1, $changed['exit']);
        $this->assertStringContainsString('paid_call_not_authorised', $changed['stdout']);
        $this->assertSame(0, $this->observation('changed')['calls']);
        $before = $this->fixture('inspect-before', 'inspect');
        $this->assertSame('ready', $before['intents'][0]['status']);
        $this->assertNull($before['intents'][0]['consumed_at']);

        $admitted = $this->invokeNative(['artisan', ...$spec['argv'], '--approved-run='.$id], 'admitted');
        $this->assertSame($spec['exit'], $admitted['exit'], $admitted['stderr'].$admitted['stdout']);
        $this->assertStringNotContainsString('paid_call_not_authorised', $admitted['stdout']);
        $observation = $this->observation('admitted');
        $this->assertTrue($observation['guest']);
        $this->assertTrue($observation['cached']);
        $this->assertTrue($observation['owned_sqlite']);
        $this->assertGreaterThan(0, $observation['calls'], 'The admitted native CLI must reach the synthetic SDK. '.$admitted['stdout'].$admitted['stderr']);
        if (in_array($case, ['team', 'tests'], true)) {
            $this->assertSame(1, $observation['calls']);
        }
        if ($case === 'reservation') {
            $this->assertSame(2, $observation['calls'], 'Both explicitly approved CLI arms must execute.');
        }
        $after = $this->fixture('inspect-after', 'inspect');
        $this->assertSame('running', $after['intents'][0]['status']);
        $this->assertNotNull($after['intents'][0]['consumed_at']);
        $this->assertSame(100000, $after['allocated']);

        $replay = $this->invokeNative(['artisan', ...$spec['argv'], '--approved-run='.$id], 'replay');
        $this->assertSame(1, $replay['exit']);
        $this->assertStringContainsString('paid_call_not_authorised', $replay['stdout']);
        $this->assertSame(0, $this->observation('replay')['calls']);
    }

    public static function races(): array
    {
        return [['launch'], ['host'], ['prompt'], ['cap']];
    }

    #[DataProvider('races')]
    public function test_competing_native_claims_have_one_winner_and_preserve_the_cap(string $case): void
    {
        $this->startWorkers(1);
        $seed = $this->fixture('seed-race', 'seed-race', $case);
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $id = $seed['ids'][$case === 'cap' ? $i : 0];
            $children[] = $this->start(['tests/fixtures/authority/process.php', $this->directory, 'race', $case, $id], 'race-'.$i);
        }
        $results = [];
        foreach ($children as &$child) {
            $this->assertSame(0, $this->finish($child, 20), file_get_contents($child['stderr']));
            $results[] = json_decode(trim(file_get_contents($child['stdout'])), true, flags: JSON_THROW_ON_ERROR);
        }
        unset($child);
        $this->assertCount(2, array_unique(array_column($results, 'pid')));
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['claimed', 'refused'], $outcomes);
        $state = $this->fixture('inspect-result', 'inspect');
        if ($case === 'cap') {
            $this->assertSame(3000000, $state['allocated']);
            $statuses = array_column($state['intents'], 'status');
            sort($statuses);
            $this->assertSame(['approved', 'ready'], $statuses);
        } else {
            $this->assertSame(100000, $state['allocated']);
            $this->assertSame('running', $state['intents'][0]['status']);
            $column = match ($case) {
                'launch' => 'consumed_at', 'host' => 'acp_host_claimed_at', 'prompt' => 'acp_prompt_claimed_at',
            };
            $this->assertNotNull($state['intents'][0][$column]);
        }
        foreach (['race-0', 'race-1'] as $tag) {
            $this->assertSame(0, $this->observation($tag)['calls']);
        }
    }

    private function startWorkers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->workers[] = $this->start(['artisan', 'queue:work', 'database', '--queue=default,scoring,overseer,commentary',
                '--sleep=1', '--timeout=1800', '--max-time=60', '--tries=1'], 'worker-'.$i);
        }
        for ($i = 0; $i < 20; $i++) {
            if ($this->fixture('ready-'.$i, 'ready')['ready'] === true
                && count(glob($this->directory.'/workers/*.json')) >= $count) {
                return;
            }
            usleep(100000);
        }
        $this->fail('The real native fixture worker never became ready.');
    }

    public function test_two_real_queue_workers_cannot_claim_the_same_paid_work_twice(): void
    {
        $this->startWorkers(2);
        $this->assertSame(['bound' => true], $this->fixture('seed-work', 'seed-work'));
        $this->assertSame(['queued' => 2], $this->fixture('enqueue-work', 'enqueue-work'));
        $deadline = microtime(true) + 45;
        do {
            if (is_file($this->directory.'/work-result-0.json') && is_file($this->directory.'/work-result-1.json')) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $file = $this->directory.'/work-result-'.$i.'.json';
            $this->assertFileExists($file, 'Both native database deliveries must report their actual result.');
            $results[] = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertCount(2, array_unique(array_column($results, 'pid')));
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['claimed', 'refused'], $outcomes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, array_sum(array_column($results, 'calls')), 'Exactly one native delivery may reach the synthetic SDK.');
        foreach ($results as $result) {
            $this->assertTrue($result['guest']);
            $this->assertTrue($result['external_exporter_closed']);
            $this->assertNull($result['exception_class']);
            $this->assertSame($result['outcome'] === 'claimed' ? 1 : 0, $result['calls']);
        }
        $state = $this->fixture('inspect-work', 'inspect');
        $this->assertSame(1, $state['works']);
        $this->assertSame(1, $state['claimed_works']);
        $this->assertSame(1, $state['probe_rows']);
        $this->assertSame(100000, $state['allocated']);
    }

    private function fixture(string $tag, string $mode, string $case = ''): array
    {
        $result = $this->invokeNative(['tests/fixtures/authority/process.php', $this->directory, $mode, $case], $tag);
        $this->assertSame(0, $result['exit'], $result['stderr'].$result['stdout']);

        try {
            return json_decode(trim($result['stdout']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('Native fixture did not emit its exact JSON contract: '.mb_substr($result['stdout'].$result['stderr'], 0, 3000));
        }
    }

    private function observation(string $tag): array
    {
        $file = $this->directory.'/observation-'.$tag.'.json';
        $this->assertFileExists($file);

        $observation = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($observation['external_exporter_closed'], 'The operational telemetry transport must be closed before native fixture work.');

        return $observation;
    }

    private function invokeNative(array $arguments, string $tag): array
    {
        $child = $this->start($arguments, $tag);
        $exit = $this->finish($child, 30);

        return ['exit' => $exit, 'stdout' => file_get_contents($child['stdout']), 'stderr' => file_get_contents($child['stderr'])];
    }

    private function start(array $arguments, string $tag): array
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/D', $tag);
        $stdout = $this->directory.'/'.$tag.'.stdout';
        $stderr = $this->directory.'/'.$tag.'.stderr';
        $this->assertFileDoesNotExist($stdout);
        if ($arguments[0] === 'artisan') {
            // Native LoadEnvironmentVariables returns before dotenv discovery
            // when this exact cache exists; CI may have its own fixture .env.
            $this->assertFileExists($this->directory.'/config.php');
            $this->assertFalse(is_link($this->directory.'/config.php'));
        }
        $env = [];
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }
        $env = [...$env, 'APP_ENV' => 'g246-native-fixture', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            // DB_DATABASE identifies the workspace/Sandbox side. The Lab has
            // its own LAB_DB_DATABASE, exactly as the normal isolation contract.
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'LAB_DB_DATABASE' => $this->directory.'/lab.sqlite', 'LAB_OWNER_USER_ID' => '1',
            'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array',
            // Laravel cache selectors are relative on Windows; their resolved
            // path is checked again in the normal cached fixture process.
            'APP_CONFIG_CACHE' => 'storage/framework/testing/'.basename($this->directory).'/config.php',
            'APP_SERVICES_CACHE' => 'storage/framework/testing/'.basename($this->directory).'/services.php',
            'APP_PACKAGES_CACHE' => 'storage/framework/testing/'.basename($this->directory).'/packages.php',
            'LAB_WORKER_HEARTBEATS_DIRECTORY' => $this->directory.'/workers',
            'G246_NATIVE_AUTHORITY_DIRECTORY' => $this->directory, 'G246_AUTHORITY_OBSERVATION_TAG' => $tag];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', ...$arguments],
            [['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], ['file', $stdout, 'w'], ['file', $stderr, 'w']],
            $pipes, base_path(), $env, ['bypass_shell' => true]);
        $this->assertIsResource($process);
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
        $this->fail('Owned native authority process exceeded its deadline.');
    }
}
