<?php

declare(strict_types=1);

namespace Tests\Concerns;

/** Pure CI boundary, usable before Composer, dotenv, files or network. */
final class NativePostgresClaim
{
    public static function validName(string $name): bool
    {
        return preg_match('/\Ag260_native_claim_[a-f0-9]{24}\z/', $name) === 1;
    }

    public static function ci(): bool
    {
        return getenv('GITHUB_ACTIONS') === 'true' && getenv('G246_CI_DISPOSABLE_POSTGRES') === '1'
            && getenv('G246_PG_PORT') === '5432' && PHP_OS_FAMILY !== 'Windows';
    }

    public static function owns(string $directory): bool
    {
        $name = (string) getenv('G260_CI_CLAIM_DATABASE');

        return self::ci() && self::validName($name)
            && ! is_link($directory.'/postgres.marker')
            && @file_get_contents($directory.'/postgres.marker') === $name
            && getenv('DB_CONNECTION') === 'pgsql' && getenv('DB_DATABASE') === ':memory:'
            && getenv('LAB_DB_DATABASE') === $name && getenv('DB_HOST') === '127.0.0.1'
            && getenv('DB_PORT') === '5432' && getenv('DB_USERNAME') === 'prism_ci_cost'
            && getenv('DB_PASSWORD') === 'g246-ci-only' && getenv('DB_SSLMODE') === 'disable'
            && getenv('DB_URL') === '';
    }
}
