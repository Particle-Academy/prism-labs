<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Application;
use RuntimeException;
use SensitiveParameter;

final class LabDatabase
{
    public const string ISOLATION_ERROR = 'The Lab database is not isolated.';

    public const string TEST_DATABASE_ERROR = 'Lab tests require in-memory SQLite.';

    /** @return array<string, string> */
    public static function names(): array
    {
        return array_combine(
            ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'],
            array_map(self::name(...), ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv']),
        );
    }

    public static function name(string $driver): string
    {
        $name = env('LAB_DB_DATABASE');

        if ($name === null) {
            return $driver === 'sqlite'
                ? (env('APP_ENV') === 'testing' ? ':memory:' : database_path('database.sqlite'))
                : 'prism_labs';
        }

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException(self::ISOLATION_ERROR);
        }

        return $name;
    }

    /** Runs after configuration loads, before any service provider registers. */
    public static function assertIsolated(#[SensitiveParameter] Application $application): void
    {
        $config = $application['config'];
        $namespace = $config->get('lab-database');
        $connections = $config->get('database.connections');
        $default = $config->get('database.default');

        if (! is_array($namespace) || ($namespace['version'] ?? null) !== 1
            || ! is_array($namespace['names'] ?? null) || ! is_array($connections)
            || ! is_string($default) || ! is_array($connections[$default] ?? null)) {
            throw new RuntimeException(self::ISOLATION_ERROR);
        }

        $testing = $application->environment('testing');
        $checked = $testing ? [$connections[$default]] : $connections;

        // Check every configured SQL route, not just the current default.
        // A later DB::connection('pgsql') must not recover the shared database.
        foreach ($checked as $connection) {
            if (! is_array($connection)) {
                throw new RuntimeException(self::ISOLATION_ERROR);
            }

            self::assertConfiguration($connection, $namespace, $application, $testing);
        }

        $application->extend('db.factory', static fn () => new LabConnectionFactory(
            $application,
            static fn (#[SensitiveParameter] array $config) => self::assertConfiguration($config, $namespace, $application, $testing),
        ));

        // Install before providers register. Lazy PDO has not connected when
        // this event fires; late named and on-demand connections are covered.
        $application['events']->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($namespace, $application, $testing): void {
            $connection = $event->connection;
            $validate = static function () use ($connection, $namespace, $application, $testing): void {
                try {
                    $config = $connection->getConfig();
                    $config['database'] = $connection->getDatabaseName();
                    self::assertConfiguration($config, $namespace, $application, $testing);
                } catch (RuntimeException $exception) {
                    // DatabaseManager caches before dispatching this event.
                    // Purge so catching the refusal cannot enable a retry.
                    $connection->disconnect();
                    $application['db']->purge($connection->getName());

                    throw $exception;
                }
            };
            $connection->beforeExecuting($validate);
            $validate();
        });
    }

    private static function assertConfiguration(
        #[SensitiveParameter] array $connection,
        #[SensitiveParameter] array $namespace,
        #[SensitiveParameter] Application $application,
        bool $testing,
    ): void {
        $driver = $connection['driver'] ?? null;
        $name = $connection['database'] ?? null;
        $error = $testing ? self::TEST_DATABASE_ERROR : self::ISOLATION_ERROR;
        if (! is_string($driver) || ! is_string($name) || trim($name) === ''
            || ! in_array($connection['url'] ?? null, [null, ''], true)
            || (isset($connection['connect_via_database']) && $connection['connect_via_database'] !== $name)
            || ($testing && ($driver !== 'sqlite' || $name !== ':memory:'))
            || (! $testing && ($namespace['names'][$driver] ?? null) !== $name)) {
            throw new RuntimeException($error);
        }

        // Laravel can give read/write PDOs a different database from the outer
        // connection. They must obey the same identity before either is used.
        foreach (['read', 'write', 'direct'] as $side) {
            if (isset($connection[$side])) {
                if (! is_array($connection[$side])) {
                    throw new RuntimeException($error);
                }

                $endpoints = isset($connection[$side][0]) ? $connection[$side] : [$connection[$side]];
                foreach ($endpoints as $overrides) {
                    if (! is_array($overrides)) {
                        throw new RuntimeException($error);
                    }
                    self::assertConfiguration(array_replace($connection, $overrides, ['read' => null, 'write' => null, 'direct' => null]), $namespace, $application, $testing);
                }
            }
        }

        if ($testing) {
            return;
        }

        $sandboxNames = [$namespace['sandbox_database'] ?? null, env('LAB_SANDBOX_DB_DATABASE'), env('DB_DATABASE')];
        if ($driver !== 'sqlite') {
            foreach ($sandboxNames as $sandboxName) {
                if (is_string($sandboxName) && $sandboxName !== ''
                    && mb_strtolower($name, 'UTF-8') === mb_strtolower($sandboxName, 'UTF-8')) {
                    throw new RuntimeException($error);
                }
            }

            return;
        }

        if ($name === ':memory:') {
            return;
        }

        // URI/shared-memory spellings have an ambiguous identity here.
        if (str_starts_with($name, 'file:') || str_contains($name, '?') || str_contains($name, "\0")) {
            throw new RuntimeException($error);
        }

        foreach ([$namespace['sandbox_sqlite'] ?? null, ...$sandboxNames] as $sandboxPath) {
            if (is_string($sandboxPath) && $sandboxPath !== '' && $sandboxPath !== ':memory:'
                && self::sameFile($name, $sandboxPath, $application)) {
                throw new RuntimeException($error);
            }
        }
    }

    private static function sameFile(string $lab, string $sandbox, Application $application): bool
    {
        $labPath = self::path($lab, $application->basePath());
        $sandboxPath = self::path($sandbox, $application->basePath('../prism-sandbox'));

        if ($labPath === $sandboxPath) {
            return true;
        }

        $left = @stat($labPath);
        $right = @stat($sandboxPath);

        return $left !== false && $right !== false && $left['ino'] > 0 && $right['ino'] > 0
            && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    private static function path(string $value, string $base): string
    {
        $resolved = realpath($value) ?: realpath($base.'/'.$value);
        $value = str_replace('\\', '/', $resolved ?: $value);
        if (! preg_match('~^(?:[A-Za-z]:/|/)~', $value)) {
            $value = str_replace('\\', '/', $base).'/'.$value;
        }

        $prefix = str_starts_with($value, '//') ? '//' : '/';
        if (preg_match('~^[A-Za-z]:/~', $value)) {
            $prefix = substr($value, 0, 3);
            $value = substr($value, 3);
        }

        $parts = [];
        foreach (explode('/', $value) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        $path = $prefix.implode('/', $parts);

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path, 'UTF-8') : $path;
    }
}
