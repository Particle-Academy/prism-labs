<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\CoreActionsStatus;
use App\Lab\LabDatabaseStatus;
use App\Lab\SessionNotReady;
use App\Lab\SessionPreflight;
use App\Lab\WorkerHeartbeats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('dogfood')]
final class SessionPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private const string HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/preflight-'.bin2hex(random_bytes(8)));
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'lab-preflight.workers.directory' => $this->directory.'/workers',
            'cache.stores.file.path' => $this->directory.'/cache',
            'cache.stores.file.lock_path' => $this->directory.'/cache',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 90,
            'team.coordinator.provider' => 'anthropic',
            'prism.providers.anthropic.api_key' => 'fixture-provider-key-private',
        ]);
        Cache::forgetDriver('file');
        $this->app->forgetInstance(WorkerHeartbeats::class);
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Http::fake(fn (Request $request) => str_contains($request->url(), '/git/ref/')
            ? Http::response($this->ref()) : Http::response($this->runs()));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        if (isset($this->directory)) {
            // This path is generated directly beneath the test-only framework directory.
            $this->app->make(Filesystem::class)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function ref(): array
    {
        return ['ref' => 'refs/heads/main', 'object' => ['sha' => self::HEAD, 'type' => 'commit']];
    }

    private function runs(): array
    {
        $runs = [];
        foreach (config('lab-preflight.core_actions.workflows') as $i => $path) {
            $runs[] = ['id' => 100 + $i, 'run_number' => 1, 'path' => $path, 'head_sha' => self::HEAD,
                'head_branch' => 'main', 'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'];
        }

        return ['total_count' => count($runs), 'workflow_runs' => $runs];
    }

    private function idle(): WorkerHeartbeats
    {
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));

        return $this->app->make(WorkerHeartbeats::class);
    }

    public static function nativeTimeouts(): array
    {
        return [
            'integer' => [60, true], 'CLI decimal' => ['60', true], 'long CLI decimal' => ['1800', true],
            'maximum' => ['3600', true], 'above maximum' => ['3601', false], 'zero string' => ['0', false],
            'zero integer' => [0, false], 'negative' => ['-1', false], 'float' => [60.5, false],
            'decimal point' => ['60.0', false], 'scientific' => ['6e1', false], 'leading space' => [' 60', false],
            'trailing space' => ['60 ', false], 'plus sign' => ['+60', false], 'missing' => [null, false],
            'boolean' => [true, false], 'array' => [[], false], 'unbounded' => [str_repeat('9', 100), false],
        ];
    }

    #[DataProvider('nativeTimeouts')]
    public function test_native_worker_options_accept_bounded_cli_decimals_and_refuse_malformed_timeouts(mixed $timeout, bool $expected): void
    {
        config(['queue.connections.database.retry_after' => 4001]);
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: $timeout)));
        $worker = app(WorkerHeartbeats::class);
        $this->assertSame($expected, $worker->check()['ready']);
        if ($expected) {
            $record = json_decode(file_get_contents(glob($this->directory.'/workers/*.json')[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame((int) $timeout, $record['timeout']);
        }
    }

    private function responses(array $bodies, int $status = 200): void
    {
        // Http::fake appends handlers. Replace the factory so a previous happy
        // handler cannot answer a negative control before its own sequence.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $sequence = Http::fakeSequence();
        foreach ($bodies as $body) {
            $sequence->push($body, $status);
        }
    }

    public function test_real_memory_database_schema_and_native_migration_ledger_are_ready(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertTrue($this->app->make(LabDatabaseStatus::class)->check()['ready']);
        DB::table('migrations')->orderByDesc('batch')->limit(1)->delete();
        $this->assertSame('setup_required', $this->app->make(LabDatabaseStatus::class)->check()['status']);
    }

    public function test_missing_application_table_refuses_without_leaking_sql(): void
    {
        DB::statement('drop table lab_settings');
        $status = $this->app->make(LabDatabaseStatus::class)->check();
        $this->assertFalse($status['ready']);
        $this->assertSame('Lab database setup is incomplete.', $status['message']);
    }

    public function test_database_name_mismatch_refuses_before_any_query(): void
    {
        config(['lab-database.names.sqlite' => 'private-db-canary']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $status = $this->app->make(LabDatabaseStatus::class)->check();
        $this->assertFalse($status['ready']);
        $this->assertSame([], DB::getQueryLog());
        $this->assertStringNotContainsString('private-db-canary', json_encode($status));
    }

    public static function serverIdentities(): array
    {
        return [
            ['pgsql', 'select current_database() as database_name'],
            ['mysql', 'select database() as database_name'],
            ['mariadb', 'select database() as database_name'],
            ['sqlsrv', 'select DB_NAME() as database_name'],
        ];
    }

    #[DataProvider('serverIdentities')]
    public function test_native_server_identity_must_match_even_when_configured_name_matches(string $driver, string $query): void
    {
        config(['lab-database.names.'.$driver => 'prism_labs']);
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('prism_labs');
        $connection->shouldReceive('selectOne')->once()->with($query)->andReturn((object) ['database_name' => 'private-wrong-database']);
        $connection->shouldNotReceive('getSchemaBuilder');
        $manager = \Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->once()->andReturn($connection);
        $status = (new LabDatabaseStatus($manager, $this->app->make(Migrator::class)))->check();
        $this->assertSame(['ready' => false, 'status' => 'unavailable', 'message' => 'Lab database identity could not be verified.'], $status);
    }

    public function test_native_events_track_busy_idle_and_stopping_without_a_web_heartbeat(): void
    {
        $worker = $this->app->make(WorkerHeartbeats::class);
        $this->assertFalse($worker->check()['ready']);
        $this->assertFalse(is_dir($this->directory.'/workers'));
        $this->idle();
        $this->assertTrue($worker->check()['ready']);
        $job = new SyncJob($this->app, '{}', 'database', 'default');
        Event::dispatch(new JobProcessing('database', $job));
        $this->assertSame('busy', $worker->check()['queues'][0]['status']);
        $this->assertFalse($worker->check()['ready']);
        Event::dispatch(new JobProcessed('database', $job));
        $this->assertTrue($worker->check()['ready']);
        Event::dispatch(new WorkerStopping);
        $this->assertFalse($worker->check()['ready']);
    }

    public function test_one_stopping_worker_cannot_hide_another_worker(): void
    {
        $first = $this->idle();
        $second = new WorkerHeartbeats(new Filesystem);
        $second->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $first->stopping(new WorkerStopping);
        $this->assertTrue($first->check()['ready']);
        $second->stopping(new WorkerStopping);
        $this->assertFalse($first->check()['ready']);
    }

    public function test_execution_preflight_accepts_only_this_native_job_without_adding_launch_capacity(): void
    {
        $worker = $this->idle();
        $this->assertFalse($worker->checkForExecution()['ready']);
        $job = $this->nativeDatabaseJob();
        Event::dispatch(new JobProcessing('database', $job));
        $this->assertFalse($worker->check()['ready']);
        $execution = $worker->checkForExecution();
        $this->assertTrue($execution['ready'], json_encode([
            'report' => $execution, 'job_queue' => $job->getQueue(),
            'execution_queue' => (new \ReflectionProperty($worker, 'executingQueue'))->getValue($worker),
            'has_worker_identity' => (new \ReflectionProperty($worker, 'worker'))->getValue($worker) !== null,
        ], JSON_THROW_ON_ERROR));
        $this->assertSame([['queue' => 'default', 'status' => 'executing']], $execution['queues']);
        app(SessionPreflight::class)->assertReadyForExecution();
        Event::dispatch(new JobProcessed('database', $job));
        $this->assertFalse($worker->checkForExecution()['ready']);
        $this->assertTrue($worker->check()['ready']);
    }

    public function test_another_busy_worker_or_an_idle_record_cannot_claim_this_process_is_executing(): void
    {
        $worker = $this->idle();
        $other = new WorkerHeartbeats(new Filesystem);
        $other->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $other->processing(new JobProcessing('database', $this->nativeDatabaseJob()));
        $this->assertFalse($worker->checkForExecution()['ready']);
        $this->assertTrue($other->checkForExecution()['ready']);
        $this->assertTrue($worker->check()['ready']);
    }

    public function test_native_execution_still_requires_its_own_valid_signed_record(): void
    {
        $worker = $this->idle();
        Event::dispatch(new JobProcessing('database', $this->nativeDatabaseJob()));
        $file = (new Filesystem)->files($this->directory.'/workers')[0]->getPathname();
        $record = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $record['signature'] = str_repeat('0', 64);
        file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR));
        $other = new WorkerHeartbeats(new Filesystem);
        $other->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $this->assertTrue($worker->check()['ready']);
        $this->assertFalse($worker->checkForExecution()['ready']);
    }

    public function test_a_failed_busy_write_cannot_relabel_a_previous_idle_record_as_execution(): void
    {
        $files = \Mockery::mock(Filesystem::class)->makePartial();
        $worker = new WorkerHeartbeats($files);
        $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $files->shouldReceive('replace')->once()->andThrow(new RuntimeException('synthetic busy write failure'));
        $worker->processing(new JobProcessing('database', $this->nativeDatabaseJob()));
        $this->assertFalse($worker->checkForExecution()['ready']);
    }

    public function test_a_stopped_or_expired_native_job_cannot_keep_execution_authority(): void
    {
        $worker = $this->idle();
        Event::dispatch(new JobProcessing('database', $this->nativeDatabaseJob()));
        $this->assertTrue($worker->checkForExecution()['ready']);
        $this->travel(61)->seconds();
        $this->assertFalse($worker->checkForExecution()['ready']);
        Event::dispatch(new WorkerStopping);
        $this->assertFalse($worker->checkForExecution()['ready']);
    }

    public function test_a_sync_job_cannot_claim_a_database_worker_queue_by_its_constructor_label(): void
    {
        $worker = $this->idle();
        Event::dispatch(new JobProcessing('database', new SyncJob($this->app, '{}', 'database', 'default')));
        $this->assertFalse($worker->checkForExecution()['ready']);
    }

    public function test_throwing_queue_metadata_never_masks_native_processing_and_cannot_supply_authority(): void
    {
        $worker = $this->idle();
        $job = new class($this->app, '{}', 'database', 'default') extends SyncJob
        {
            public function getQueue()
            {
                throw new RuntimeException('synthetic queue metadata failure');
            }
        };
        Event::dispatch(new JobProcessing('database', $job));
        $this->assertFalse($worker->checkForExecution()['ready']);
        $this->assertSame('busy', $worker->check()['queues'][0]['status']);
    }

    private function nativeDatabaseJob(): DatabaseJob
    {
        // Reserve from Laravel's actual database queue in this SQLite memory
        // fixture; the synthetic event-only payload is never fired.
        $queue = $this->app['queue']->connection('database');
        $queue->pushRaw('{"job":"owned-event-only","data":{}}', 'default');
        $job = $queue->pop('default');
        $this->assertInstanceOf(DatabaseJob::class, $job);

        return $job;
    }

    public function test_stale_heartbeat_and_future_timestamp_are_not_available(): void
    {
        $worker = $this->idle();
        $this->travel(31)->seconds();
        $this->assertFalse($worker->check()['ready']);
        $this->travelBack();
        $this->travel(10)->seconds();
        $this->idle();
        $this->travelBack();
        $this->assertFalse($worker->check()['ready']);
    }

    public function test_worker_scope_and_signature_cannot_be_relabelled(): void
    {
        $worker = $this->idle();
        $file = glob($this->directory.'/workers/*.json')[0];
        $record = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('payload', $record);
        $record['queues'] = ['default', 'scoring', 'forged'];
        file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR));
        $this->assertFalse($worker->check()['ready']);
        $this->idle();
        config(['queue.connections.database.retry_after' => 120]);
        $this->assertFalse((new WorkerHeartbeats(new Filesystem))->check()['ready']);
    }

    public function test_optional_native_worker_metadata_and_retry_window_fail_closed(): void
    {
        $worker = $this->app->make(WorkerHeartbeats::class);
        $worker->looping(new Looping('database', 'default,scoring'));
        $this->assertSame('misconfigured', $worker->check()['queues'][0]['status']);
        $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 90)));
        $this->assertFalse($worker->check()['ready']);
        $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $this->assertTrue($worker->check()['ready']);
    }

    public function test_wrong_connection_queue_and_unbounded_timeout_are_not_ready(): void
    {
        $worker = $this->app->make(WorkerHeartbeats::class);
        $worker->looping(new Looping('redis', 'default,scoring', new WorkerOptions(timeout: 60)));
        $this->assertFalse($worker->check()['ready']);
        $worker->looping(new Looping('database', 'other', new WorkerOptions(timeout: 60)));
        $this->assertFalse($worker->check()['ready']);
        $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 0)));
        $this->assertFalse($worker->check()['ready']);
    }

    public function test_unknown_or_malformed_retry_window_is_not_available_capacity(): void
    {
        foreach ([null, '90', [], 0, 60] as $retry) {
            config(['queue.connections.database.retry_after' => $retry]);
            $this->app->forgetInstance(WorkerHeartbeats::class);
            $worker = $this->app->make(WorkerHeartbeats::class);
            $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
            $this->assertFalse($worker->check()['ready']);
        }
    }

    public function test_invalid_directory_and_throwing_observability_preserve_native_outcome(): void
    {
        config(['lab-preflight.workers.directory' => base_path('../must-not-write-preflight')]);
        $worker = $this->idle();
        $this->assertFalse($worker->check()['ready']);
        $this->assertDirectoryDoesNotExist(base_path('../must-not-write-preflight'));
        config(['lab-preflight.workers.directory' => $this->directory.'/workers']);
        $files = \Mockery::mock(Filesystem::class);
        $files->shouldReceive('ensureDirectoryExists')->andThrow(new RuntimeException('private-io-canary'));
        $worker = new WorkerHeartbeats($files);
        $native = new RuntimeException('native-job-failure');
        try {
            $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
            throw $native;
        } catch (RuntimeException $exception) {
            $this->assertSame($native, $exception);
        }
        $this->assertFalse($worker->check()['ready']);
    }

    public function test_malformed_and_excess_worker_evidence_never_reports_ready(): void
    {
        $worker = $this->idle();
        file_put_contents(glob($this->directory.'/workers/*.json')[0], '{');
        $this->assertFalse($worker->check()['ready']);
        $this->idle();
        for ($i = 0; $i < 256; $i++) {
            file_put_contents($this->directory.'/workers/ignored-'.$i, '');
        }
        $this->assertFalse($worker->check()['ready']);
    }

    public function test_observability_and_logging_failure_preserve_an_actual_native_job_exception(): void
    {
        $files = \Mockery::mock(Filesystem::class);
        $files->shouldReceive('ensureDirectoryExists')->andThrow(new RuntimeException('private-io-canary'));
        $worker = new WorkerHeartbeats($files);
        $this->app->instance(WorkerHeartbeats::class, $worker);
        Log::partialMock()->shouldReceive('warning')->andThrow(new RuntimeException('private-log-canary'));
        $worker->looping(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $expected = new RuntimeException('native-job-failure');
        PreflightThrowingJob::$failure = $expected;
        try {
            Bus::dispatchSync(new PreflightThrowingJob);
            $this->fail('The native job exception must propagate.');
        } catch (RuntimeException $actual) {
            $this->assertSame($expected, $actual);
        } finally {
            PreflightThrowingJob::$failure = null;
        }
        $this->assertFalse($worker->check()['ready']);
    }

    public function test_exact_main_requires_all_five_native_workflows(): void
    {
        $status = $this->app->make(CoreActionsStatus::class)->check();
        $this->assertTrue($status['ready']);
        $this->assertSame(self::HEAD, $status['head']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/actions/runs?')
            && $request['head_sha'] === self::HEAD && $request['event'] === 'push' && $request['branch'] === 'main');
        Http::assertSentCount(2);
    }

    public function test_core_actions_reads_are_public_even_with_an_obsolete_token_setting(): void
    {
        $this->assertArrayNotHasKey('token', config('lab-preflight.core_actions'));
        config(['lab-preflight.core_actions.token' => 'synthetic-obsolete-token-never-send']);
        $this->assertTrue($this->app->make(CoreActionsStatus::class)->check()['ready']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization'));
    }

    public static function failedCoreRuns(): array
    {
        return [
            'old head' => ['head_sha', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
            'pull request' => ['event', 'pull_request'], 'other branch' => ['head_branch', 'feature'],
            'queued' => ['status', 'queued'], 'failed' => ['conclusion', 'failure'],
            'cancelled' => ['conclusion', 'cancelled'], 'publish fallback' => ['path', '.github/workflows/publish.yml'],
            'missing id' => ['id', null], 'invalid number' => ['run_number', '999'],
        ];
    }

    #[DataProvider('failedCoreRuns')]
    public function test_incomplete_wrong_subject_or_failed_core_runs_are_not_green(string $key, mixed $value): void
    {
        $runs = $this->runs();
        $runs['workflow_runs'][0][$key] = $value;
        $this->responses([$this->ref(), $runs]);
        $this->assertFalse($this->app->make(CoreActionsStatus::class)->check()['ready']);
    }

    public function test_newer_failure_cannot_fall_back_to_an_older_green_run(): void
    {
        $runs = $this->runs();
        $new = $runs['workflow_runs'][0];
        $new['id'] = 999;
        $new['run_number'] = 2;
        $new['conclusion'] = 'failure';
        $runs['workflow_runs'][] = $new;
        $runs['total_count']++;
        $this->responses([$this->ref(), $runs]);
        $this->assertSame('blocked', $this->app->make(CoreActionsStatus::class)->check()['status']);
    }

    public function test_truncated_or_oversized_and_unavailable_core_responses_are_private(): void
    {
        $runs = $this->runs();
        $runs['total_count']++;
        $this->responses([$this->ref(), $runs]);
        $reader = $this->app->make(CoreActionsStatus::class);
        $this->assertSame('unknown', $reader->check()['status']);
        $this->responses(['private-body-canary'], 500);
        $this->assertSame('unknown', $reader->check(fresh: true)['status']);
        $this->responses([str_repeat('private-body-canary', 70000)]);
        $result = $reader->check(fresh: true);
        $this->assertFalse($result['ready']);
        $this->assertStringNotContainsString('private-body-canary', json_encode($result));
    }

    public function test_cached_core_is_bounded_but_assert_ready_always_observes_fresh_main(): void
    {
        $this->idle();
        $preflight = $this->app->make(SessionPreflight::class);
        $this->assertTrue($preflight->report()['ready']);
        $this->assertTrue($preflight->report()['ready']);
        Http::assertSentCount(2);
        $runs = $this->runs();
        $runs['workflow_runs'][0]['status'] = 'queued';
        $this->responses([$this->ref(), $runs]);
        $this->expectException(SessionNotReady::class);
        $this->expectExceptionMessage('Lab session preflight is not ready.');
        $preflight->assertReady();
    }

    public function test_all_required_positive_controls_allow_assert_ready_without_a_paid_call(): void
    {
        $this->idle();
        $this->app->make(SessionPreflight::class)->assertReady();
        Http::assertSentCount(2);
        $this->assertTrue($this->app->make(SessionPreflight::class)->report()['ready']);
    }

    public static function requiredPrerequisites(): array
    {
        return [['workers'], ['database'], ['providers'], ['core_actions']];
    }

    #[DataProvider('requiredPrerequisites')]
    public function test_each_required_prerequisite_independently_blocks_the_run_api(string $missing): void
    {
        $worker = $this->idle();
        if ($missing === 'workers') {
            $worker->stopping(new WorkerStopping);
        } elseif ($missing === 'database') {
            DB::statement('drop table lab_settings');
        } elseif ($missing === 'providers') {
            config(['prism.providers.anthropic.api_key' => null]);
        } else {
            $runs = $this->runs();
            array_pop($runs['workflow_runs']);
            $runs['total_count']--;
            $this->responses([$this->ref(), $runs, $this->ref(), $runs]);
        }
        $report = $this->app->make(SessionPreflight::class)->report();
        foreach ($report['checks'] as $key => $check) {
            $this->assertSame($key !== $missing, $check['ready'], $key);
        }
        $this->expectException(SessionNotReady::class);
        $this->app->make(SessionPreflight::class)->assertReady();
    }

    public function test_cached_green_expires_and_future_dated_cache_is_not_trusted(): void
    {
        $reader = $this->app->make(CoreActionsStatus::class);
        $this->assertTrue($reader->check()['ready']);
        $this->travel(301)->seconds();
        $this->assertFalse($reader->cached()['ready']);
        $this->travelBack();
        $this->travel(-10)->seconds();
        $this->assertFalse($reader->cached()['ready']);
    }

    public function test_main_reference_and_ambiguous_workflow_numbers_fail_closed(): void
    {
        $ref = $this->ref();
        $ref['object']['type'] = 'tag';
        $this->responses([$ref]);
        $reader = $this->app->make(CoreActionsStatus::class);
        $this->assertFalse($reader->check()['ready']);
        $runs = $this->runs();
        $runs['workflow_runs'][] = $runs['workflow_runs'][0];
        $runs['total_count']++;
        $this->responses([$this->ref(), $runs]);
        $this->assertSame('unknown', $reader->check(fresh: true)['status']);
    }

    public function test_provider_projection_has_presence_only_and_optional_keys_do_not_block(): void
    {
        config(['prism.providers.openai.api_key' => null, 'prism.providers.anthropic.url' => 'https://private-url-canary.example']);
        $this->idle();
        $report = $this->app->make(SessionPreflight::class)->report();
        $this->assertTrue($report['ready']);
        $rows = array_column($report['checks']['providers']['providers'], null, 'key');
        $this->assertSame('present', $rows['anthropic']['presence']);
        $this->assertSame('missing', $rows['openai']['presence']);
        $this->assertSame('not_required', $rows['ollama']['presence']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['fixture-provider-key-private', 'private-url-canary', 'api_key', 'signature', 'fixture-only-preflight-key'] as $canary) {
            $this->assertStringNotContainsString($canary, $encoded);
        }
    }

    public function test_provider_key_sentinel_is_absent_from_each_output_surface(): void
    {
        $sentinel = 'sk-test-SENTINEL-123';
        config([
            'prism.providers.anthropic.api_key' => $sentinel,
            'logging.default' => 'preflight_fixture',
            'logging.channels.preflight_fixture' => ['driver' => 'single', 'path' => $this->directory.'/preflight.log', 'level' => 'debug'],
        ]);
        Log::forgetChannel('preflight_fixture');
        Log::channel('preflight_fixture')->info('preflight-fixture-log-control');
        $this->idle();
        $json = $this->getJson('/lab/preflight')->assertOk()->assertJsonPath('ready', true);
        $this->assertStringNotContainsString($sentinel, $json->getContent());

        $home = $this->get('/lab')->assertOk();
        $this->assertStringContainsString('text/html', $home->headers->get('Content-Type'));
        $this->assertStringNotContainsString($sentinel, $home->getContent());

        $cacheFiles = (new Filesystem)->allFiles($this->directory.'/cache');
        $this->assertNotEmpty($cacheFiles, 'A real file cache entry must have been written.');
        $this->assertLessThan(8, count($cacheFiles));
        $cache = implode('', array_map(static fn ($file): string => file_get_contents($file->getPathname()), $cacheFiles));
        $this->assertStringContainsString('checked_at', $cache);
        $this->assertStringNotContainsString($sentinel, $cache);

        $logs = file_get_contents($this->directory.'/preflight.log');
        $this->assertStringContainsString('preflight-fixture-log-control', $logs);
        $this->assertStringNotContainsString($sentinel, $logs);
    }

    public static function malformedCredentials(): array
    {
        return [[null], [''], ['  '], [false], [true], [123], [['secret']]];
    }

    #[DataProvider('malformedCredentials')]
    public function test_absent_or_malformed_coordinator_keys_do_not_count_as_present(mixed $credential): void
    {
        config(['prism.providers.anthropic.api_key' => $credential]);
        $report = $this->app->make(SessionPreflight::class)->report();
        $this->assertFalse($report['checks']['providers']['ready']);
    }

    public function test_keyless_and_unknown_coordinators_are_unverified(): void
    {
        foreach (['ollama', 'unknown', ['malformed']] as $selected) {
            config(['team.coordinator.provider' => $selected]);
            $this->assertFalse($this->app->make(SessionPreflight::class)->report()['checks']['providers']['ready']);
        }
    }

    public function test_loopback_json_and_home_report_the_same_snapshot_without_false_zero_metrics(): void
    {
        DB::statement('drop table lab_settings');
        $this->freezeTime();
        $expected = $this->app->make(SessionPreflight::class)->report();
        $this->getJson('/lab/preflight')->assertOk()->assertExactJson($expected)->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/lab')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Lab/Cockpit')
            ->where('preflight', $expected)->where('metrics', null)->where('dataAvailable', false)
            ->has('benchmarks', 0)->has('consensus', 0)->has('recent', 0));
    }

    public function test_home_positive_control_renders_actual_empty_database_counts(): void
    {
        $this->idle();
        $this->get('/lab')->assertOk()->assertInertia(fn (Assert $page) => $page->where('preflight.ready', true)
            ->where('dataAvailable', true)->where('metrics.activeRuns', 0)->where('metrics.dailyTokens', 0));
    }

    public function test_forwarded_loopback_cannot_bypass_the_raw_peer_guard(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->withHeader('X-Forwarded-For', '127.0.0.1')
            ->getJson('/lab/preflight')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_nonlocal_environment_refuses_even_an_actual_loopback(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/lab/preflight')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_actual_child_queue_worker_observes_capacity_then_removes_its_own_evidence(): void
    {
        $env = array_fill_keys(array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]), false);
        foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'TMPDIR'] as $key) {
            if (getenv($key) !== false) {
                $env[$key] = getenv($key);
            }
        }
        $env = array_replace($env, ['APP_ENV' => 'testing', 'APP_KEY' => 'fixture-only', 'LAB_DB_DATABASE' => ':memory:',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr', 'QUEUE_CONNECTION' => 'database',
            'PRISM_LEARNINGS_PATH' => './storage/framework/testing/learnings']);
        $process = new Process([PHP_BINARY, base_path('tests/fixtures/preflight-worker.php')], base_path(), $env, timeout: 20);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['loops' => 1, 'observed_ready' => true, 'after_stop_ready' => false, 'records_after_stop' => 0, 'exit' => 0], $result);
    }
}

final class PreflightThrowingJob implements ShouldQueue
{
    use Queueable;

    public static ?RuntimeException $failure = null;

    public function handle(): void
    {
        throw self::$failure;
    }
}
