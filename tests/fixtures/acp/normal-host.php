<?php

declare(strict_types=1);

use App\Http\Controllers\Lab\AcpController;
use App\Lab\PaidRunContext;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Lab\SessionPreflight;
use App\Models\AcpTurn;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\NativeAcpReadinessProvider;
use Tests\Concerns\NativeAcpStopWorker;
use Tests\Concerns\NativeAcpTurnFixture;

function requireNativeAcp(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

[$script, $directory, $mode, $provider, $step] = $argv;
requireNativeAcp(in_array($mode, ['prepare', 'enqueue', 'inspect', 'stop'], true), 'Unknown fixture mode.');
requireNativeAcp(in_array($provider, ['claude', 'codex'], true), 'Unknown fixture provider.');
requireNativeAcp(in_array($step, ['hello', 'one', 'two', 'hook', 'approval', 'invalid-config'], true), 'Unknown fixture step.');
$repo = dirname(__DIR__, 3);
require $repo.'/vendor/autoload.php';
$directory = NativeAcpTurnFixture::directory($directory);
requireNativeAcp(getenv('DB_CONNECTION') === 'sqlite' && getenv('DB_DATABASE') === ':memory:'
    && str_replace('\\', '/', (string) getenv('LAB_DB_DATABASE')) === $directory.'/lab.sqlite', 'Wrong owned database selector.');
requireNativeAcp(getenv('APP_CONFIG_CACHE') === 'storage/framework/testing/'.basename($directory).'/config.php', 'Wrong owned cache selector.');
requireNativeAcp(is_file($directory.'/empty.env') && filesize($directory.'/empty.env') === 0, 'Unexpected fixture dotenv.');
$app = require $repo.'/bootstrap/app.php';
$app->useEnvironmentPath($directory)->loadEnvironmentFrom('empty.env');
$app->make(Kernel::class)->bootstrap();
requireNativeAcp(str_replace('\\', '/', app('db')->connection()->getDatabaseName()) === $directory.'/lab.sqlite', 'Wrong actual database identity.');
if ($mode === 'prepare') {
    requireNativeAcp(! app()->configurationIsCached(), 'Fixture already prepared.');
    app(Kernel::class)->call('migrate', ['--force' => true]);
    $owner = User::factory()->create(['name' => 'Synthetic ACP fixture owner', 'email' => 'native-acp@example.invalid']);
    requireNativeAcp($owner->id === 1, 'Unexpected fixture owner ID.');
    config(['app.env' => 'local', 'app.debug' => false, 'lab_authority.owner_user_id' => $owner->id,
        'app.providers' => [...config('app.providers'), NativeAcpReadinessProvider::class],
        'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache',
        'logging.channels.single.path' => $directory.'/framework.log',
        'database.connections.sqlite.password' => 'synthetic-host-db-password',
        'testing-native-acp.evidence_directory' => $directory]);
    $file = fopen($directory.'/config.php', 'x');
    requireNativeAcp(is_resource($file), 'Owned cache must be new.');
    fwrite($file, '<?php return '.var_export(config()->all(), true).';'.PHP_EOL);
    fclose($file);
    echo json_encode(['mode' => $mode, 'owned_database' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
requireNativeAcp(app()->environment('local') && app()->configurationIsCached()
    && app('native-acp-exporter-closed') === true, 'Normal closed-exporter cached host required.');
if ($mode === 'stop') {
    NativeAcpStopWorker::dispatch()->onQueue('default');
    echo json_encode(['mode' => $mode, 'owned_stop_queued' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'enqueue') {
    app(SessionPreflight::class)->assertReady();
    Auth::guard('web')->setUser(User::query()->findOrFail(1));
    $input = ['provider' => $provider, 'model' => $provider === 'claude' ? 'haiku' : 'fixture-model',
        'prompt' => match ($step) {
            'hook' => 'hook FutureTool', 'approval' => 'approval', 'invalid-config' => 'must refuse', default => $step,
        }];
    if ($step === 'two') {
        $prior = json_decode(file_get_contents($directory.'/queued-one.json'), true, flags: JSON_THROW_ON_ERROR);
        $input['continue'] = $prior['turn_id'];
    }
    $scope = app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', $input);
    $authority = app(RunAuthority::class);
    $intent = $authority->draft($scope, '0.1');
    $authority->approve($intent->id, $intent->digest);
    $authority->go($intent->id, $intent->digest, '0.1', 'GO');
    $authority->consume($intent->id, $scope);
    $response = app(PaidRunContext::class)->within($intent->id, $scope,
        fn () => app(AcpController::class)->send(Request::create('/lab/acp', 'POST', $input)));
    requireNativeAcp($response->getStatusCode() === 202, 'Native producer refused.');
    $turn = AcpTurn::query()->findOrFail($response->getData(true)['id']);
    requireNativeAcp(app('db')->table('jobs')->where('queue', 'overseer')->count() === 1, 'Exactly one native queued job required.');
    $row = ['turn_id' => $turn->id, 'intent_id' => $intent->id, 'work_id' => $turn->paid_work_id];
    $file = fopen($directory.'/queued-'.$step.'.json', 'x');
    requireNativeAcp(is_resource($file), 'Native enqueue must be new.');
    fwrite($file, json_encode($row, JSON_THROW_ON_ERROR));
    fclose($file);
    echo json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
$queued = json_decode(file_get_contents($directory.'/queued-'.$step.'.json'), true, flags: JSON_THROW_ON_ERROR);
$turn = AcpTurn::query()->findOrFail($queued['turn_id']);
$intent = LabRunIntent::query()->findOrFail($queued['intent_id']);
$work = LabPaidWork::query()->findOrFail($queued['work_id']);
echo json_encode(['attributes' => $turn->getAttributes(), 'work_claimed' => $work->claimed_at !== null,
    'host_claimed' => $intent->acp_host_claimed_at !== null, 'prompt_claimed' => $intent->acp_prompt_claimed_at !== null,
    'native_job_consumed' => app('db')->table('jobs')->where('queue', 'overseer')->count() === 0,
    'failed_jobs' => app('db')->table('failed_jobs')->count(), 'inspector_guest' => Auth::guard('web')->guest(),
    'exporter_closed' => app('native-acp-exporter-closed') === true], JSON_THROW_ON_ERROR).PHP_EOL;
