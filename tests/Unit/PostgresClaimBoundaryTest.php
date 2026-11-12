<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Concerns\NativePostgresClaim;

/** Local refusal controls only: never create a service, database or connection. */
final class PostgresClaimBoundaryTest extends TestCase
{
    /** Validate only a private marker and environment fields, never a DB. */
    private function withOwnedContext(Closure $operation): void
    {
        $directory = dirname(__DIR__, 2).'/storage/framework/testing/native-pg-fields-'.bin2hex(random_bytes(8));
        $name = 'g260_native_claim_'.str_repeat('a', 24);
        $values = ['GITHUB_ACTIONS' => false, 'G260_CI_CLAIM_DATABASE' => $name,
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => ':memory:', 'LAB_DB_DATABASE' => $name,
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432', 'DB_USERNAME' => 'prism_ci_cost',
            'DB_PASSWORD' => 'g246-ci-only', 'DB_SSLMODE' => 'disable', 'DB_URL' => false];
        $before = [];
        foreach ($values as $key => $value) {
            $before[$key] = getenv($key);
        }
        mkdir($directory, 0700);
        file_put_contents($directory.'/postgres.marker', $name, LOCK_EX);
        try {
            foreach ($values as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
            $operation($directory);
        } finally {
            foreach ($before as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
            if (is_file($directory.'/postgres.marker')) {
                unlink($directory.'/postgres.marker');
            }
            rmdir($directory);
        }
    }

    public function test_valid_fields_accept_absent_or_empty_url_but_never_grant_ci_authority(): void
    {
        $this->withOwnedContext(function (string $directory): void {
            foreach ([false, ''] as $url) {
                putenv($url === false ? 'DB_URL' : 'DB_URL=');
                $this->assertTrue(NativePostgresClaim::contextMatches($directory));
                $this->assertFalse(NativePostgresClaim::owns($directory));
            }
        });
    }

    public static function changedContext(): array
    {
        return [
            'driver' => ['DB_CONNECTION', 'sqlite'], 'workspace' => ['DB_DATABASE', 'prism_labs'],
            'lab' => ['LAB_DB_DATABASE', 'prism_labs'], 'host' => ['DB_HOST', 'fixture.invalid'],
            'port' => ['DB_PORT', '15432'], 'role' => ['DB_USERNAME', 'postgres'],
            'password' => ['DB_PASSWORD', 'unreviewed'], 'ssl' => ['DB_SSLMODE', 'prefer'],
            'url' => ['DB_URL', 'pgsql://fixture.invalid/prism_labs'], 'falsy-url' => ['DB_URL', '0'],
            'name' => ['G260_CI_CLAIM_DATABASE', 'prism_labs'],
            'upper-name' => ['G260_CI_CLAIM_DATABASE', 'G260_NATIVE_CLAIM_'.str_repeat('A', 24)],
            'marker' => ['marker', 'unowned'], 'missing-marker' => ['marker', null],
        ];
    }

    #[DataProvider('changedContext')]
    public function test_every_changed_field_refuses_without_any_ci_or_database(string $field, ?string $value): void
    {
        $this->withOwnedContext(function (string $directory) use ($field, $value): void {
            $this->assertTrue(NativePostgresClaim::contextMatches($directory));
            if ($field === 'marker') {
                if ($value === null) {
                    unlink($directory.'/postgres.marker');
                } else {
                    file_put_contents($directory.'/postgres.marker', $value, LOCK_EX);
                }
            } else {
                putenv($field.'='.$value);
            }
            $this->assertFalse(NativePostgresClaim::contextMatches($directory));
            $this->assertFalse(NativePostgresClaim::owns($directory));
        });
    }

    private function process(array $args = [], array $extra = []): Process
    {
        $env = array_fill_keys(array_keys(getenv()), false);
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }

        return new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/authority/postgres-claim.php', ...$args],
            null, [...$env, ...$extra], timeout: 10);
    }

    public function test_local_context_refuses_before_files_sdk_or_network(): void
    {
        $process = $this->process();
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('refused before SDK, files or network', $process->getErrorOutput());
    }

    #[DataProvider('reserved')]
    public function test_operational_default_traversal_and_malformed_names_are_refused(string $name): void
    {
        $process = $this->process(['--validate-database-name', $name]);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame(['allowed' => false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function reserved(): array
    {
        return array_map(fn ($name) => [$name], ['prism_labs', 'prism_sandbox', 'postgres', 'template0', 'template1',
            '../prism_labs', 'g260_native_claim_";DROP DATABASE prism_labs;--', 'g260_native_claim_'.str_repeat('a', 23)]);
    }

    public function test_incomplete_or_changed_ci_context_is_also_refused_before_io(): void
    {
        foreach ([
            ['GITHUB_ACTIONS' => 'true'],
            ['GITHUB_ACTIONS' => 'true', 'G246_CI_DISPOSABLE_POSTGRES' => '1'],
            ['GITHUB_ACTIONS' => 'true', 'G246_CI_DISPOSABLE_POSTGRES' => '1', 'G246_PG_PORT' => '15432'],
        ] as $context) {
            $process = $this->process([], $context);
            $process->run();
            $this->assertSame(2, $process->getExitCode());
            $this->assertStringContainsString('refused before SDK, files or network', $process->getErrorOutput());
        }
    }

    public function test_a_generated_name_cannot_adopt_any_workspace_or_prior_fixture_database(): void
    {
        $name = 'g260_native_claim_'.str_repeat('a', 24);
        foreach (['DB_DATABASE', 'LAB_DB_DATABASE', 'PGDATABASE', 'G260_CI_CLAIM_DATABASE'] as $variable) {
            $process = $this->process(['--validate-database-name', $name], [$variable => $name]);
            $process->run();
            $this->assertSame(2, $process->getExitCode(), $variable);
        }
        $positive = $this->process(['--validate-database-name', $name]);
        $positive->run();
        $this->assertSame(0, $positive->getExitCode());
        $this->assertSame(['allowed' => true], json_decode($positive->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
