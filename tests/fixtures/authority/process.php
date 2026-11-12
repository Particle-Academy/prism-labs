<?php

declare(strict_types=1);

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\FrozenTestDefinition;
use App\Benchmarks\RsiLaneProfile;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Lab\SessionPreflight;
use App\Models\BenchmarkLane;
use App\Models\CompactionProbeRun;
use App\Models\LabAuthoritySession;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\NativeAdmissionContention;
use Tests\Concerns\NativeAuthorityFixture;
use Tests\Concerns\NativeAuthorityProvider;
use Tests\Concerns\NativeAuthorityWork;
use Tests\Concerns\NativePostgresClaim;

$repo = dirname(__DIR__, 3);
$directory = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$case = $argv[3] ?? '';
require_once $repo.'/tests/Concerns/NativePostgresClaim.php';
$ownedSqlite = getenv('DB_CONNECTION') === 'sqlite' && getenv('DB_DATABASE') === ':memory:'
    && getenv('LAB_DB_DATABASE') === $directory.'/lab.sqlite';
$parent = realpath($repo.'/storage/framework/testing');
$resolved = realpath($directory);
if ($resolved === false || $parent === false || dirname($resolved) !== $parent || is_link($directory)
    || preg_match('/^native-authority-[a-f0-9]{16}$/D', basename($resolved)) !== 1
    || @file_get_contents($resolved.'/fixture.marker') !== "G246 native authority fixture v1\n"
    || (! $ownedSqlite && ! NativePostgresClaim::owns($resolved))
    || getenv('APP_CONFIG_CACHE') !== 'storage/framework/testing/'.basename($resolved).'/config.php'
    || ! is_file($resolved.'/fixture.env') || filesize($resolved.'/fixture.env') !== 0
    || ! in_array($mode, ['prepare', 'ready', 'seed-cli', 'seed-race', 'seed-work', 'enqueue-work', 'claim-contention', 'seed-rsi-cost', 'inspect-rsi-cost', 'race', 'inspect'], true)) {
    throw new RuntimeException('Refuse a non-owned native authority process.');
}
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
// Initial preparation has no cache yet. Its dotenv path is an explicit empty
// fixture, while real Artisan children subsequently skip dotenv via this cache.
$app->useEnvironmentPath($directory)->loadEnvironmentFrom('fixture.env');
if ($mode !== 'prepare' && (! is_file($directory.'/config.php') || is_link($directory.'/config.php'))) {
    throw new RuntimeException('Native authority cache must exist before bootstrap.');
}
$app->make(Kernel::class)->bootstrap();
NativeAuthorityFixture::directory($directory);
$expectedDriver = $ownedSqlite ? 'sqlite' : 'pgsql';
$expectedDatabase = $ownedSqlite ? $directory.'/lab.sqlite' : getenv('G260_CI_CLAIM_DATABASE');
if (app('db')->connection()->getDriverName() !== $expectedDriver || app('db')->connection()->getDatabaseName() !== $expectedDatabase) {
    throw new RuntimeException('Wrong native authority database.');
}
if (! $ownedSqlite) {
    $identity = DB::selectOne('SELECT current_database() AS name, current_user AS owner');
    if ($identity->name !== $expectedDatabase || $identity->owner !== 'prism_ci_cost') {
        throw new RuntimeException('Wrong disposable PostgreSQL identity.');
    }
}

if ($mode === 'prepare') {
    if (app()->configurationIsCached() || User::query()->getConnection()->getSchemaBuilder()->hasTable('users')) {
        throw new RuntimeException('Native authority fixture already prepared.');
    }
    if ($ownedSqlite) {
        if (app(Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Native SQLite migration failed.');
        }
    } else {
        // Permanent full-history gate, only after the private marker, CI,
        // generated database name, connection and server identity guards above.
        $historyFile = $repo.'/native-postgres-history-evidence.json';
        if (file_exists($historyFile)) {
            throw new RuntimeException('Refuse replay of PostgreSQL migration history.');
        }
        $tables = static fn (): array => array_column(DB::select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'), 'tablename');
        $history = ['database' => $expectedDatabase, 'driver' => 'pgsql', 'status' => 'BLOCK',
            'migrationSha256' => hash_file('sha256', $repo.'/database/migrations/2026_11_08_000002_create_lab_run_authorities.php'),
            'first' => null, 'rollback' => null, 'second' => null];
        try {
            if ($tables() !== [] || app(Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
                throw new RuntimeException('Fresh PostgreSQL history did not migrate.');
            }
            $snapshot = static fn (): array => ['migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
                'tables' => $tables()];
            $history['first'] = $snapshot();
            if ($history['first']['migrations'] === [] || ! in_array('lab_paid_works', $history['first']['tables'], true)) {
                throw new RuntimeException('PostgreSQL history was incomplete.');
            }
            if (app(Kernel::class)->call('migrate:reset', ['--force' => true]) !== 0) {
                throw new RuntimeException('PostgreSQL full-history rollback failed.');
            }
            $history['rollback'] = ['command' => 'migrate:reset', 'migrations' => DB::table('migrations')->count(), 'tables' => $tables()];
            if ($history['rollback']['migrations'] !== 0 || $history['rollback']['tables'] !== ['migrations']) {
                throw new RuntimeException('PostgreSQL rollback left application tables or migration records.');
            }
            if (app(Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
                throw new RuntimeException('PostgreSQL history did not migrate a second time.');
            }
            $history['second'] = $snapshot();
            if ($history['first'] !== $history['second']) {
                throw new RuntimeException('PostgreSQL migration histories or table sets differ.');
            }
            $history['status'] = 'PASS';
        } finally {
            file_put_contents($historyFile, json_encode($history, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL, LOCK_EX);
        }
    }
    $owner = User::factory()->create(['name' => 'Owned native authority owner', 'email' => 'native-authority@example.invalid']);
    config(['app.env' => 'local', 'app.debug' => false, 'lab_authority.owner_user_id' => $owner->id,
        'app.providers' => [NativeAuthorityProvider::class, ...config('app.providers')],
        'queue.default' => 'database', 'queue.connections.database.retry_after' => 1900,
        'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache',
        'logging.channels.single.path' => $directory.'/framework.log', 'logging.default' => 'single',
        'filesystems.disks.local.root' => $directory.'/storage',
        'lab-preflight.workers.directory' => $directory.'/workers',
        'team.learnings_path' => $directory.'/learnings', 'testing-native-authority.directory' => $directory,
        // Native tool providers construct their clients even with no attached
        // capability. Satisfy validation with a synthetic token; no sidecar runs.
        'prism-browser.sidecar.token' => str_repeat('owned-native-browser-', 2),
        'team.coordinator.provider' => 'anthropic', 'prism.providers.anthropic.api_key' => 'synthetic-native-authority-key']);
    if ($case === 'rsi-cost') {
        config(['testing-native-authority.rsi_cost' => true,
            // SQLite's default deferred queue transactions can fail on lock
            // upgrade before the cost boundary. Isolate that store contention
            // in this throwaway concurrent fixture; production stays unchanged.
            'database.connections.sqlite.busy_timeout' => 5000,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            'filesystems.disks.native-rsi-cost' => ['driver' => 'local', 'root' => $directory.'/workspaces', 'throw' => true],
            'prism-workspace.disk' => 'native-rsi-cost', 'prism-workspace.root' => 'w',
            'prism-harness.pricing' => ['anthropic' => ['claude-sonnet-5' => ['inputPerMTok' => 0, 'outputPerMTok' => 60000, 'toolOverheadTokens' => 286]]]]);
    }
    $file = fopen($directory.'/config.php', 'x');
    fwrite($file, '<?php return '.var_export(config()->all(), true).';'.PHP_EOL);
    fclose($file);
    echo json_encode(['prepared' => true, 'owner' => $owner->id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if (! app()->configurationIsCached() || ! app()->environment('local')) {
    throw new RuntimeException('Normal local cached fixture configuration is required.');
}
if (realpath(app()->getCachedConfigPath()) !== realpath($directory.'/config.php')) {
    throw new RuntimeException('Wrong native authority configuration cache.');
}
if ($mode === 'ready') {
    $report = app(SessionPreflight::class)->report(true);
    echo json_encode(['ready' => $report['ready']], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
$authority = app(RunAuthority::class);
if ($mode === 'claim-contention') {
    $work = json_decode(file_get_contents($directory.'/work.json'), true, flags: JSON_THROW_ON_ERROR);
    NativeAdmissionContention::dispatch($directory, $work);
    echo json_encode(['queued' => 1], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'inspect-rsi-cost') {
    echo json_encode(['calls' => DB::table('lab_call_reservations')->get()->all(),
        'lanes' => BenchmarkLane::query()->get(['id', 'status', 'proof'])->toArray(),
        'judgments' => DB::table('benchmark_scores')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'seed-rsi-cost') {
    if (config('testing-native-authority.rsi_cost') !== true) {
        throw new RuntimeException('Expected dedicated native cost fixture.');
    }
    $owner = User::query()->findOrFail(config('lab_authority.owner_user_id'));
    Auth::guard('web')->setUser($owner);
    DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'], ['value' => '["anthropic:claude-sonnet-5"]']);
    $reference = app(RsiLaneProfile::class)->installedReference();
    $lanes = [];
    foreach (['first', 'second'] as $laneId) {
        $lanes[] = ['id' => $laneId, 'language' => 'php', 'package' => 'particle-academy/prism-harness', 'package_ref' => $reference,
            'provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'harness' => 'prism-harness', 'harness_ref' => $reference,
            'mode' => RsiLaneProfile::MODE, 'agent' => null,
            'bounds' => ['max_calls' => 1, 'max_steps' => 1, 'timeout_seconds' => 120, 'limits' => ['max_output_tokens' => 10, 'max_tool_calls' => 0]]];
    }
    $definition = FrozenTestDefinition::fromArray(['brief' => 'Synthetic native shared allocation test.', 'fixtures' => [],
        'assertions' => [['id' => 'result', 'instrument_id' => 'later', 'expected' => true]],
        'instruments' => [['id' => 'later', 'version' => '1.0.0', 'sha256' => str_repeat('a', 64)]], 'lanes' => $lanes, 'cost_cap_microusd' => 1000000]);
    $designer = app(BenchmarkDesigner::class);
    $spec = $designer->draftTest('Native shared reservation', $definition);
    $spec = $designer->approve($designer->requestApproval($spec));
    $scope = app(RunScopeFactory::class)->fromAction('benchmarks.launch', $spec->id, []);
    $intent = $authority->draft($scope, '1');
    $authority->approve($intent->id, $intent->digest);
    $authority->go($intent->id, $intent->digest, '1', 'GO');
    $authority->consume($intent->id, $scope);
    $run = app(PaidRunContext::class)->within($intent->id, $scope, function () use ($designer, $spec) {
        $run = $designer->launch($spec);
        app(BenchmarkWorkflow::class)->dispatch($run);

        return $run;
    });
    Auth::guard('web')->logout();
    echo json_encode(['run' => $run->id, 'lanes' => $run->lanes()->pluck('id')->all()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'seed-work') {
    Auth::guard('web')->setUser(User::query()->findOrFail(config('lab_authority.owner_user_id')));
    $scope = app(RunScopeFactory::class)->fromAction('benchmarks.compaction-probe', 'lab:agent', []);
    $intent = $authority->draft($scope, '0.1');
    $authority->approve($intent->id, $intent->digest);
    $authority->go($intent->id, $intent->digest, '0.1', 'GO');
    $authority->consume($intent->id, $scope);
    $work = app(PaidRunContext::class)->within($intent->id, $scope, fn () => app(PaidWorkRegistry::class)->bind(
        'compaction.reservation', $intent->id, ['reserved' => true, 'trigger' => 5000, 'keep' => 3]));
    Auth::guard('web')->logout();
    $file = fopen($directory.'/work.json', 'x');
    fwrite($file, json_encode(['intent' => $intent->id, 'work' => $work->id], JSON_THROW_ON_ERROR).PHP_EOL);
    fclose($file);
    echo json_encode(['bound' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'enqueue-work') {
    $data = json_decode(file_get_contents($directory.'/work.json'), true, flags: JSON_THROW_ON_ERROR);
    $work = LabPaidWork::query()->findOrFail($data['work']);
    if ($work->intent_id !== $data['intent'] || $work->claimed_at !== null || $work->kind !== 'compaction.reservation') {
        throw new RuntimeException('Changed native work fixture.');
    }
    for ($i = 0; $i < 2; $i++) {
        NativeAuthorityWork::dispatch($directory, $data['intent'], $data['work'], $i)->onQueue('default');
    }
    echo json_encode(['queued' => 2], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'seed-cli' || $mode === 'seed-race') {
    $owner = User::query()->findOrFail(config('lab_authority.owner_user_id'));
    Auth::guard('web')->setUser($owner);
    $ids = [];
    $count = $mode === 'seed-race' && $case === 'cap' ? 2 : 1;
    for ($i = 0; $i < $count; $i++) {
        $spec = $mode === 'seed-cli' ? NativeAuthorityFixture::cli($case) : ['action' => 'team.ask', 'input' => ['question' => 'Owned race '.$case.' '.$i]];
        if ($mode === 'seed-race' && in_array($case, ['host', 'prompt'], true)) {
            $spec = ['action' => 'acp.send', 'input' => ['provider' => 'claude', 'model' => 'owned-native-model', 'prompt' => 'Owned ACP claim race']];
        }
        $scope = app(RunScopeFactory::class)->fromAction($spec['action'], 'lab:agent', $spec['input']);
        $cap = $case === 'cap' && $mode === 'seed-race' ? '3' : '0.1';
        $intent = $authority->draft($scope, $cap);
        $authority->approve($intent->id, $intent->digest);
        if ($case !== 'cap' || $mode !== 'seed-race') {
            $authority->go($intent->id, $intent->digest, $cap, 'GO');
        }
        if ($case === 'prompt' && $mode === 'seed-race') {
            $authority->claimAcpHost($intent->id, $scope);
        }
        $ids[] = $intent->id;
    }
    Auth::guard('web')->logout();
    echo json_encode(['ids' => $ids], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'inspect') {
    echo json_encode(['allocated' => LabAuthoritySession::query()->firstOrFail()->allocated_microusd,
        'intents' => LabRunIntent::query()->get(['id', 'status', 'consumed_at', 'acp_host_claimed_at', 'acp_prompt_claimed_at'])->toArray(),
        'users' => User::query()->count(), 'works' => LabPaidWork::query()->count(),
        'claimed_works' => LabPaidWork::query()->whereNotNull('claimed_at')->count(),
        'probe_rows' => CompactionProbeRun::query()->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
$id = $argv[4] ?? '';
$intent = LabRunIntent::query()->findOrFail($id);
$scope = app(RunScopeFactory::class)->fromAction($intent->action, $intent->resource, $intent->payload['input']);
if ($case === 'cap') {
    Auth::guard('web')->setUser(User::query()->findOrFail(config('lab_authority.owner_user_id')));
}
NativeAuthorityFixture::barrier($directory, $case);
if ($case === 'cap' && getenv('G246_AUTHORITY_OBSERVATION_TAG') === 'race-1') {
    NativeAuthorityFixture::waitForCapObservation($directory, 'cap-first-read');
}
try {
    match ($case) {
        'launch' => $authority->consume($id, $scope),
        'host' => $authority->claimAcpHost($id, $scope),
        'prompt' => $authority->claimAcpPrompt($id, $scope),
        'cap' => $authority->go($id, $intent->digest, '3', 'GO'),
        default => throw new RuntimeException('Unknown native claim race.'),
    };
    $result = ['outcome' => 'claimed', 'pid' => getmypid()];
} catch (PaidRunNotAuthorised) {
    $result = ['outcome' => 'refused', 'pid' => getmypid()];
}
Auth::guard('web')->logout();
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
