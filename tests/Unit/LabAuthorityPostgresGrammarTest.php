<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Compile the real migration without opening a PostgreSQL connection. */
final class LabAuthorityPostgresGrammarTest extends TestCase
{
    public function test_the_primary_key_precedes_the_preserved_self_foreign_key(): void
    {
        $env = array_fill_keys(array_keys(getenv()), false);
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/authority/schema-grammar.php', 'pgsql'],
            null, [...$env, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
                'LAB_DB_DATABASE' => ':memory:', 'OTEL_PHP_DETECTORS' => 'none'], timeout: 10);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $data['pdoAttempts']);
        $this->assertSame(hash_file('sha256', dirname(__DIR__, 2).'/database/migrations/2026_11_08_000002_create_lab_run_authorities.php'), $data['migrationSha256']);
        $queries = $data['queries'];
        foreach (['lab_authority_sessions', 'lab_run_intents', 'lab_paid_works'] as $table) {
            $this->assertCount(1, array_filter($queries, fn ($sql) => str_starts_with($sql, 'create table "'.$table.'"')));
            $this->assertCount(1, array_filter($queries, fn ($sql) => str_starts_with($sql, 'alter table "'.$table.'"') && str_contains($sql, 'primary key ("id")')));
        }
        $primary = array_keys(array_filter($queries, fn ($sql) => str_starts_with($sql, 'alter table "lab_paid_works"') && str_contains($sql, 'primary key ("id")')));
        $selfForeign = array_keys(array_filter($queries, fn ($sql) => str_starts_with($sql, 'alter table "lab_paid_works"') && str_contains($sql, 'references "lab_paid_works"')));
        $this->assertCount(1, $selfForeign, 'The self-reference must stay present, not be dropped to make migration pass.');
        $this->assertStringContainsString('foreign key ("parent_id") references "lab_paid_works" ("id") on delete restrict', $queries[$selfForeign[0]]);
        $this->assertLessThan($selfForeign[0], $primary[0], 'PostgreSQL needs the referenced primary key before the self-FK statement.');
    }
}
