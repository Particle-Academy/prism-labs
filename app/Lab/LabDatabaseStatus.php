<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

final class LabDatabaseStatus
{
    public function __construct(private readonly DatabaseManager $database, private readonly Migrator $migrator) {}

    public function check(): array
    {
        try {
            $connection = $this->database->connection();
            $driver = $connection->getDriverName();
            $expected = config('lab-database.names.'.$driver);
            if (! is_string($expected) || $connection->getDatabaseName() !== $expected) {
                return $this->unavailable();
            }
            $actual = match ($driver) {
                'pgsql' => $connection->selectOne('select current_database() as database_name')->database_name,
                'mysql', 'mariadb' => $connection->selectOne('select database() as database_name')->database_name,
                'sqlsrv' => $connection->selectOne('select DB_NAME() as database_name')->database_name,
                'sqlite' => $this->sqliteIdentity($connection->select('PRAGMA database_list')),
                default => null,
            };
            $matches = $driver === 'sqlite' && $expected !== ':memory:'
                ? $this->samePath($expected, $actual) : $actual === $expected;
            if (! $matches) {
                return $this->unavailable();
            }
            foreach (['lab_settings', 'benchmark_specs', 'benchmark_runs', 'consensus_runs', 'lab_operations', 'jobs', 'harness_threads', 'harness_session_state'] as $table) {
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    return $this->setupRequired();
                }
            }
            if (! $this->migrator->repositoryExists()) {
                return $this->setupRequired();
            }
            $files = $this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]);
            if ($files === [] || count($files) > 1000 || array_diff(array_keys($files), $this->migrator->getRepository()->getRan()) !== []) {
                return $this->setupRequired();
            }

            return ['ready' => true, 'status' => 'ready', 'message' => 'Lab database identity and setup verified.'];
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    private function sqliteIdentity(array $databases): ?string
    {
        foreach ($databases as $database) {
            if (($database->name ?? null) === 'main' && is_string($database->file ?? null)) {
                return $database->file === '' ? ':memory:' : $database->file;
            }
        }

        return null;
    }

    private function samePath(string $expected, mixed $actual): bool
    {
        if (! is_string($actual) || $actual === ':memory:') {
            return false;
        }
        $left = realpath($expected) ?: realpath(base_path($expected));
        $right = realpath($actual);
        if ($left === false || $right === false) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function unavailable(): array
    {
        return ['ready' => false, 'status' => 'unavailable', 'message' => 'Lab database identity could not be verified.'];
    }

    private function setupRequired(): array
    {
        return ['ready' => false, 'status' => 'setup_required', 'message' => 'Lab database setup is incomplete.'];
    }
}
