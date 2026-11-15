<?php

declare(strict_types=1);

use App\Lab\NativeCostObserver;
use App\Models\LabRunIntent;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismServiceProvider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
function allowedDatabase(string $name): bool
{
    $forbidden = ['prism_labs', 'prism_sandbox', 'prism', 'sandbox', 'postgres', 'template0', 'template1',
        getenv('DB_DATABASE'), getenv('LAB_DB_DATABASE'), getenv('PGDATABASE')];

    return preg_match('/\Ag246_native_cost_[a-f0-9]{24}\z/', $name) === 1 && ! in_array(strtolower($name), array_map(fn ($s) => strtolower((string) $s), $forbidden), true);
}

// Pure refusal controls, before any SDK load, directory creation or connection.
if (($argv[1] ?? '') === '--validate-database-name') {
    $name = $argv[2] ?? '';
    echo json_encode(['allowed' => allowedDatabase($name)], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(allowedDatabase($name) ? 0 : 2);
}
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('G246_CI_DISPOSABLE_POSTGRES') !== '1'
    || PHP_OS_FAMILY === 'Windows' || getenv('G246_PG_PORT') !== '5432') {
    fwrite(STDERR, "CI disposable PostgreSQL boundary refused before SDK, files or network.\n");
    exit(2);
}

$repo = dirname(__DIR__, 3);
require $repo.'/vendor/autoload.php';
$mode = $argv[1] ?? 'parent';
$sessionId = '11111111-1111-4111-8111-111111111111';
$intentId = '22222222-2222-4222-8222-222222222222';
$workId = '33333333-3333-4333-8333-333333333333';

function configureDatabase(string $repo, string $database): void
{
    check(allowedDatabase($database), 'Refuse existing/shared/default database.');
    $app = new Application($repo);
    $app->instance('config', new Repository(['app' => ['env' => 'testing'], 'prism' => [],
        'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1',
            'port' => 5432, 'database' => $database, 'username' => 'prism_ci_cost', 'password' => 'g246-ci-only', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable']]]]));
    $app->detectEnvironment(fn () => 'testing');
    Facade::setFacadeApplication($app);
    $app->register(DatabaseServiceProvider::class);
    $app->register(PrismServiceProvider::class);
    $http = new Factory;
    $http->preventStrayRequests();
    $app->instance(Factory::class, $http);
    $identity = DB::selectOne('SELECT current_database() AS name, current_user AS owner');
    check($identity->name === $database && $identity->owner === 'prism_ci_cost', 'Wrong disposable database/owner.');
}

if (in_array($mode, ['hold', 'compete', 'after'], true)) {
    $database = $argv[2] ?? '';
    $directory = realpath($argv[3] ?? '');
    $parent = realpath($repo.'/storage/framework/testing');
    check($directory !== false && $parent !== false && dirname($directory) === $parent && ! is_link($directory)
        && preg_match('/\Anative-pg-[a-f0-9]{16}\z/', basename($directory)) === 1
        && file_get_contents($directory.'/database.marker') === $database, 'Wrong private PostgreSQL fixture marker.');
    configureDatabase($repo, $database);
    $native = new NativeCostObserver($intentId, $sessionId, $workId, str_repeat('a', 64), 'pg-root', 'anthropic', 'pg-model',
        function () use ($intentId): LabRunIntent {
            $row = DB::table('lab_run_intents')->where('id', $intentId)->first();

            return (new LabRunIntent)->forceFill((array) $row);
        });
    $observer = new class($native, $mode, $directory) implements CallCostObserver
    {
        public function __construct(private NativeCostObserver $native, private string $mode, private string $directory) {}

        public function reserving(CallReservation $call, RunContext $run): void
        {
            $this->native->reserving($call, $run);
            check(DB::transactionLevel() === 0, 'Reservation is not committed.');
            if ($this->mode === 'hold') {
                file_put_contents($this->directory.'/ready', $call->callId, LOCK_EX);
                $deadline = microtime(true) + 15;
                while (! is_file($this->directory.'/release')) {
                    check(microtime(true) < $deadline, 'Owned PostgreSQL worker timed out.');
                    usleep(10000);
                }
            }
        }

        public function settled(CallSettlement $call, RunContext $run): void
        {
            $this->native->settled($call, $run);
        }
    };
    $fake = Prism::fake([TextResponseFake::make()->withUsage(new Usage(1, 1, cost: .04))]);
    $context = RunContext::root('pg-root', new RunBudget(1, .15, maxOutputTokensPerCall: 10),
        ['anthropic' => ['pg-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 10000]]], $observer);
    $status = 'completed';
    $code = null;
    try {
        (new BudgetedCalls(CallBudget::forRun($context, 'anthropic', 'pg-model', [])))
            ->text(Prism::text()->using('anthropic', 'pg-model')->withPrompt('Synthetic PostgreSQL native account.'), $context, static function (): void {}, static function (): void {});
    } catch (RunNotPermitted $e) {
        $status = 'refused';
        $code = $e->code();
    }
    $count = -1;
    $fake->assertRequest(function (array $requests) use (&$count): void {
        $count = count($requests);
    });
    echo json_encode(['pid' => getmypid(), 'status' => $status, 'code' => $code, 'fakeProviderInvocations' => $count], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
check($mode === 'parent', 'Unknown PostgreSQL fixture mode.');
check(! file_exists($repo.'/native-postgres-cost-evidence.json'), 'Refuse replay of a prior PostgreSQL proof.');
$database = 'g246_native_cost_'.bin2hex(random_bytes(12));
check(allowedDatabase($database), 'Reserved PostgreSQL name.');
$parent = $repo.'/storage/framework/testing';
if (! is_dir($parent)) {
    mkdir($parent, 0700, true);
}
$directory = $parent.'/native-pg-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
file_put_contents($directory.'/database.marker', $database);
$admin = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres;sslmode=disable', 'prism_ci_cost', 'g246-ci-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = false;
$children = [];
$records = [];
try {
    check((int) $admin->query('SELECT COUNT(*) FROM pg_database WHERE datname='.$admin->quote($database))->fetchColumn() === 0, 'Disposable database already exists.');
    $admin->exec('CREATE DATABASE "'.$database.'" OWNER prism_ci_cost');
    $created = true;
    configureDatabase($repo, $database);
    Schema::create('lab_authority_sessions', function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->bigInteger('allocated_microusd');
    });
    Schema::create('lab_run_intents', function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->uuid('authority_session_id');
        $t->bigInteger('cap_microusd');
    });
    Schema::create('lab_paid_works', fn (Blueprint $t) => $t->uuid('id')->primary());
    Schema::create('benchmark_lanes', fn (Blueprint $t) => $t->uuid('id')->primary());
    (require $repo.'/database/migrations/2026_11_11_000001_record_native_call_reservations.php')->up();
    DB::table('lab_authority_sessions')->insert(['id' => $sessionId, 'allocated_microusd' => 150000]);
    DB::table('lab_run_intents')->insert(['id' => $intentId, 'authority_session_id' => $sessionId, 'cap_microusd' => 150000]);
    DB::table('lab_paid_works')->insert(['id' => $workId]);
    $start = function (string $action) use (&$children, $directory, $database, $repo): int {
        $out = $directory.'/'.$action.'.stdout';
        $err = $directory.'/'.$action.'.stderr';
        $env = ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'testing', 'OTEL_PHP_DETECTORS' => 'none',
            'GITHUB_ACTIONS' => 'true', 'G246_CI_DISPOSABLE_POSTGRES' => '1', 'G246_PG_PORT' => '5432'];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, $action, $database, $directory],
            [['file', '/dev/null', 'r'], ['file', $out, 'w'], ['file', $err, 'w']], $pipes, $repo, $env, ['bypass_shell' => true]);
        check(is_resource($process), 'Cannot start owned PostgreSQL worker.');
        $children[] = ['process' => $process, 'out' => $out, 'err' => $err];

        return array_key_last($children);
    };
    $finish = function (int $key) use (&$children, &$records): array {
        $deadline = microtime(true) + 20;
        do {
            $status = proc_get_status($children[$key]['process']);
            if (! $status['running']) {
                proc_close($children[$key]['process']);
                $children[$key]['process'] = null;
                check($status['exitcode'] === 0, 'PostgreSQL worker failed: '.substr(file_get_contents($children[$key]['err']), -2000));
                $result = json_decode(trim(file_get_contents($children[$key]['out'])), true, flags: JSON_THROW_ON_ERROR);
                $records[] = $result;

                return $result;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Owned PostgreSQL child timed out.');
    };
    $first = $start('hold');
    $deadline = microtime(true) + 12;
    while (! is_file($directory.'/ready')) {
        check(microtime(true) < $deadline, 'No native committed PostgreSQL reservation.');
        usleep(10000);
    }
    check(DB::table('lab_call_reservations')->whereNull('settled_at')->count() === 1, 'Native pending reservation missing.');
    $competitor = $finish($start('compete'));
    check($competitor['status'] === 'refused' && $competitor['code'] === 'account_exhausted' && $competitor['fakeProviderInvocations'] === 0, 'PostgreSQL competitor exceeded account.');
    file_put_contents($directory.'/release', 'release');
    $winner = $finish($first);
    check($winner['status'] === 'completed' && $winner['fakeProviderInvocations'] === 1 && $winner['pid'] !== $competitor['pid'], 'PostgreSQL positive worker failed.');
    $after = $finish($start('after'));
    check($after['status'] === 'completed' && $after['fakeProviderInvocations'] === 1, 'Known settlement failed to restore capacity.');
    check(DB::table('lab_call_reservations')->count() === 2 && (int) DB::table('lab_call_reservations')->sum('charged_microusd') === 80000, 'Incorrect PostgreSQL final account.');
    file_put_contents($repo.'/native-postgres-cost-evidence.json', json_encode(['status' => 'PENDING_CLEANUP', 'database' => $database,
        'uniqueCreatedDatabase' => true, 'existingLabSandboxWorkspaceNamesExcluded' => true, 'nativeProductionObserver' => true,
        'records' => $records, 'realProviderRequests' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    foreach ($children as $child) {
        if (is_resource($child['process'])) {
            proc_terminate($child['process']);
            proc_close($child['process']);
        }
    }
    if ($created) {
        DB::disconnect();
        check(allowedDatabase($database), 'Refuse cleanup of existing/shared database.');
        $admin->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
        check((int) $admin->query('SELECT COUNT(*) FROM pg_database WHERE datname='.$admin->quote($database))->fetchColumn() === 0, 'Disposable database was not dropped.');
        $file = $repo.'/native-postgres-cost-evidence.json';
        if (is_file($file)) {
            $proof = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $proof['databaseDropped'] = true;
            $proof['status'] = 'PASS';
            file_put_contents($file, json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }
    // Private logs remain as CI artifacts; no service or existing database survives.
}
echo "PASS native PostgreSQL reservation race; unique test database created and dropped.\n";
