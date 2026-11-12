<?php

declare(strict_types=1);

use Tests\Concerns\NativePostgresClaim;

require_once dirname(__DIR__, 2).'/Concerns/NativePostgresClaim.php';

function claimCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
function freshClaimName(string $name): bool
{
    return NativePostgresClaim::validName($name) && ! in_array($name, [
        getenv('DB_DATABASE'), getenv('LAB_DB_DATABASE'), getenv('PGDATABASE'), getenv('G260_CI_CLAIM_DATABASE'),
    ], true);
}

// Refusals must not load the application, discover dotenv or open a connection.
if (($argv[1] ?? '') === '--validate-database-name') {
    $allowed = freshClaimName($argv[2] ?? '');
    echo json_encode(['allowed' => $allowed], JSON_THROW_ON_ERROR), PHP_EOL;
    exit($allowed ? 0 : 2);
}
if (! NativePostgresClaim::ci() || count($argv) !== 1) {
    fwrite(STDERR, "CI disposable PostgreSQL claim boundary refused before SDK, files or network.\n");
    exit(2);
}

$repo = dirname(__DIR__, 3);
$reportFile = $repo.'/native-postgres-claim-evidence.json';
$raceFile = $repo.'/native-postgres-claim-race.json';
$historyFile = $repo.'/native-postgres-history-evidence.json';
foreach ([$reportFile, $raceFile, $historyFile, $repo.'/native-postgres-claim.stdout', $repo.'/native-postgres-claim.stderr', $repo.'/native-postgres-claim-junit.xml'] as $file) {
    claimCheck(! file_exists($file), 'Refuse replay of a prior PostgreSQL claim proof.');
}
$database = 'g260_native_claim_'.bin2hex(random_bytes(12));
claimCheck(freshClaimName($database), 'Refuse an existing or operational database name.');
$admin = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres;sslmode=disable',
    'prism_ci_cost', 'g246-ci-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = false;
$process = null;
$report = ['database' => $database, 'created' => false, 'dropped' => false, 'remainingSessions' => null, 'migrationHistory' => null, 'race' => null];
$failure = null;
try {
    claimCheck((int) $admin->query('SELECT COUNT(*) FROM pg_database WHERE datname='.$admin->quote($database))->fetchColumn() === 0,
        'Disposable claim database already exists.');
    $admin->exec('CREATE DATABASE "'.$database.'" OWNER prism_ci_cost');
    $created = $report['created'] = true;
    $env = ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'LAB_DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'OTEL_PHP_DETECTORS' => 'none',
        'GITHUB_ACTIONS' => 'true', 'G246_CI_DISPOSABLE_POSTGRES' => '1', 'G246_PG_PORT' => '5432',
        'G260_CI_CLAIM_DATABASE' => $database];
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', 'vendor/bin/phpunit',
        '--filter', 'NativeRunAuthorityProcessTest::test_two_real_queue_workers_cannot_claim_the_same_paid_work_twice',
        '--fail-on-warning', '--log-junit', $repo.'/native-postgres-claim-junit.xml'],
        [['file', '/dev/null', 'r'], ['file', $repo.'/native-postgres-claim.stdout', 'w'],
            ['file', $repo.'/native-postgres-claim.stderr', 'w']], $pipes, $repo, $env, ['bypass_shell' => true]);
    claimCheck(is_resource($process), 'Cannot start the owned PostgreSQL native claim test.');
    $deadline = microtime(true) + 180;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) {
            $exit = $status['exitcode'];
            proc_close($process);
            $process = null;
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    claimCheck($process === null, 'Owned PostgreSQL claim test exceeded its deadline.');
    claimCheck($exit === 0, 'The real PostgreSQL queue-worker claim race failed. Inspect retained stdout/stderr.');
    claimCheck(is_file($historyFile), 'The full PostgreSQL migration cycle did not emit evidence.');
    $history = json_decode(file_get_contents($historyFile), true, flags: JSON_THROW_ON_ERROR);
    claimCheck($history['status'] === 'PASS' && $history['driver'] === 'pgsql' && $history['database'] === $database,
        'Wrong PostgreSQL migration history identity or result.');
    claimCheck($history['migrationSha256'] === hash_file('sha256', $repo.'/database/migrations/2026_11_08_000002_create_lab_run_authorities.php')
        && $history['first'] === $history['second'] && $history['first']['migrations'] !== []
        && $history['rollback'] === ['command' => 'migrate:reset', 'migrations' => 0, 'tables' => ['migrations']],
        'Incomplete PostgreSQL migrate, full rollback, migrate cycle.');
    $report['migrationHistory'] = $history;
    claimCheck(is_file($raceFile), 'The PostgreSQL race did not emit a native result.');
    $report['race'] = json_decode(file_get_contents($raceFile), true, flags: JSON_THROW_ON_ERROR);
    claimCheck($report['race']['database'] === $database, 'Wrong PostgreSQL race database.');
    $report['remainingSessions'] = (int) $admin->query('SELECT COUNT(*) FROM pg_stat_activity WHERE datname='.$admin->quote($database))->fetchColumn();
    claimCheck($report['remainingSessions'] === 0, 'Native PostgreSQL claim workers left a connection open.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($created) {
        claimCheck(NativePostgresClaim::validName($database), 'Refuse an unsafe cleanup target.');
        // FORCE is restricted to this freshly generated database, including
        // failure cleanup when a contained worker has not yet closed its socket.
        $admin->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
        $report['dropped'] = (int) $admin->query('SELECT COUNT(*) FROM pg_database WHERE datname='.$admin->quote($database))->fetchColumn() === 0;
    }
    $report['status'] = $failure === null && $report['created'] && $report['dropped'] ? 'PASS' : 'BLOCK';
    file_put_contents($reportFile, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL, LOCK_EX);
}
claimCheck($report['dropped'], 'Disposable PostgreSQL claim database survived cleanup.');
if ($failure !== null) {
    throw $failure;
}
echo json_encode($report, JSON_THROW_ON_ERROR), PHP_EOL;
