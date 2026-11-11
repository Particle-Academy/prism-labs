<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LabMigrationHistoryTest extends TestCase
{
    public function test_the_whole_native_history_migrates_rolls_back_and_migrates_again(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"));
        $current = '(none)';
        Event::listen(MigrationStarted::class, static function (MigrationStarted $event) use (&$current): void {
            $current = basename((new \ReflectionClass($event->migration))->getFileName()).' '.$event->method;
        });
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $first = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
            $this->assertNotEmpty($first);
            $this->assertContains('2026_11_08_000001_record_benchmark_approval_identity', $first);
            $this->assertContains('2026_11_08_000002_create_lab_run_authorities', $first);
            $this->assertContains('2026_11_08_000003_bind_paid_business_work', $first);
            $this->assertSame(0, Artisan::call('migrate:reset', ['--force' => true]));
            $this->assertSame(0, DB::table('migrations')->count());
            $this->assertFalse(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasTable('lab_run_intents'));
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertSame($first, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
            $this->assertTrue(Schema::hasColumn('benchmark_specs', 'approved_by'));
            $this->assertTrue(Schema::hasColumn('benchmark_specs', 'approved_digest'));
            foreach (['overseer_turns', 'acp_turns', 'consensus_runs', 'benchmark_runs'] as $table) {
                $this->assertTrue(Schema::hasColumn($table, 'approved_run_id'));
                $this->assertTrue(Schema::hasColumn($table, 'paid_work_id'));
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException('Lab migration history failed at '.$current.': '.$error->getMessage(), previous: $error);
        }
    }
}
