<?php

declare(strict_types=1);

use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Lab\SessionPreflight;
use App\Models\CompactionProbeRun;
use App\Models\LabAuthoritySession;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\NativeAuthorityFixture;
use Tests\Concerns\NativeAuthorityProvider;
use Tests\Concerns\NativeAuthorityWork;

$repo = dirname(__DIR__, 3);
$directory = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$case = $argv[3] ?? '';
$parent = realpath($repo.'/storage/framework/testing');
$resolved = realpath($directory);
if ($resolved === false || $parent === false || dirname($resolved) !== $parent || is_link($directory)
    || preg_match('/^native-authority-[a-f0-9]{16}$/D', basename($resolved)) !== 1
    || @file_get_contents($resolved.'/fixture.marker') !== "G246 native authority fixture v1\n"
    || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:'
    || getenv('LAB_DB_DATABASE') !== $resolved.'/lab.sqlite'
    || getenv('APP_CONFIG_CACHE') !== 'storage/framework/testing/'.basename($resolved).'/config.php'
    || ! is_file($resolved.'/fixture.env') || filesize($resolved.'/fixture.env') !== 0
    || ! in_array($mode, ['prepare', 'ready', 'seed-cli', 'seed-race', 'seed-work', 'enqueue-work', 'race', 'inspect'], true)) {
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
if (app('db')->connection()->getDriverName() !== 'sqlite' || app('db')->connection()->getDatabaseName() !== $directory.'/lab.sqlite') {
    throw new RuntimeException('Wrong native authority database.');
}

if ($mode === 'prepare') {
    if (app()->configurationIsCached() || User::query()->getConnection()->getSchemaBuilder()->hasTable('users')) {
        throw new RuntimeException('Native authority fixture already prepared.');
    }
    app(Kernel::class)->call('migrate', ['--force' => true]);
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
