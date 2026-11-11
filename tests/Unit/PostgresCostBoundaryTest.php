<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Refusal only: these local controls never connect to PostgreSQL or load the SDK. */
final class PostgresCostBoundaryTest extends TestCase
{
    private function process(array $args = [], array $additional = []): Process
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[$key] = false;
        }
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }

        return new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/authority/postgres-cost.php', ...$args], null, [...$env, ...$additional], timeout: 10);
    }

    public function test_local_or_incomplete_ci_context_refuses_before_any_database_connection(): void
    {
        $process = $this->process();
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('refused before SDK, files or network', $process->getErrorOutput());
    }

    #[DataProvider('reserved')]
    public function test_existing_lab_sandbox_default_and_traversal_names_are_rejected(string $name): void
    {
        $process = $this->process(['--validate-database-name', $name]);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame(['allowed' => false], json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function reserved(): array
    {
        return array_map(fn ($name) => [$name], ['prism_labs', 'prism_sandbox', 'postgres', 'template1', '../prism_labs', 'g246_native_cost_";DROP DATABASE prism_labs;--']);
    }

    public function test_even_a_generated_name_equal_to_the_workspace_database_is_rejected(): void
    {
        $name = 'g246_native_cost_'.str_repeat('a', 24);
        $process = $this->process(['--validate-database-name', $name], ['DB_DATABASE' => $name]);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame(['allowed' => false], json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR));
        $positive = $this->process(['--validate-database-name', $name]);
        $positive->run();
        $this->assertSame(0, $positive->getExitCode());
        $this->assertSame(['allowed' => true], json_decode(trim($positive->getOutput()), true, flags: JSON_THROW_ON_ERROR));
    }
}
