<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Local refusal controls only: never create a service, database or connection. */
final class PostgresClaimBoundaryTest extends TestCase
{
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
