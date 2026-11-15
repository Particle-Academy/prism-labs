<?php

declare(strict_types=1);
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;

// Offline compiler only. No application bootstrap or usable PDO exists.
$repo = dirname(__DIR__, 3);
$driver = $argv[1] ?? 'pgsql';
if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
    throw new RuntimeException('Unknown offline schema grammar.');
}
require $repo.'/vendor/autoload.php';
$attempts = 0;
$pdo = function () use (&$attempts): never {
    $attempts++;
    throw new LogicException('Offline schema grammar must never open PDO.');
};
$connection = $driver === 'pgsql'
    ? new PostgresConnection($pdo, 'g260_offline_grammar', '', ['driver' => 'pgsql', 'version' => '17.11'])
    : new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite', 'version' => SQLite3::version()['versionString']]);
$connection->useDefaultSchemaGrammar();
$container = new Container;
Container::setInstance($container);
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::setFacadeApplication($container);
$file = $repo.'/database/migrations/2026_11_08_000002_create_lab_run_authorities.php';
$migration = require $file;
$queries = $connection->pretend(fn () => $migration->up());
echo json_encode(['driver' => $driver, 'pdoAttempts' => $attempts, 'migrationSha256' => hash_file('sha256', $file),
    'framework' => InstalledVersions::getPrettyVersion('laravel/framework'),
    'queries' => array_column($queries, 'query')], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
