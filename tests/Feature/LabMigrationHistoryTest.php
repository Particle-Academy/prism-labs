<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LabMigrationHistoryTest extends TestCase
{
    public function test_paid_work_self_reference_rejects_missing_parents_and_restricts_parent_deletion(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $owner = User::factory()->create();
        $session = (string) Str::uuid7();
        $intent = (string) Str::uuid7();
        $parent = (string) Str::uuid7();
        DB::table('lab_authority_sessions')->insert(['id' => $session, 'owner_user_id' => $owner->id]);
        // Synthetic unapproved rows test DB integrity; they grant no paid authority.
        DB::table('lab_run_intents')->insert(['id' => $intent, 'authority_session_id' => $session,
            'action' => 'schema.check', 'resource' => 'owned-fixture', 'payload' => '{}',
            'cap_microusd' => 1, 'digest' => str_repeat('a', 64), 'expires_at' => now()->addMinute()]);
        $row = ['intent_id' => $intent, 'kind' => 'schema.check', 'payload' => '{}', 'payload_digest' => str_repeat('a', 64)];
        DB::table('lab_paid_works')->insert([...$row, 'id' => $parent, 'work_key' => 'parent']);
        DB::table('lab_paid_works')->insert([...$row, 'id' => (string) Str::uuid7(), 'parent_id' => $parent, 'work_key' => 'child']);
        foreach ([
            fn () => DB::table('lab_paid_works')->insert([...$row, 'id' => (string) Str::uuid7(),
                'parent_id' => (string) Str::uuid7(), 'work_key' => 'missing-parent']),
            fn () => DB::table('lab_paid_works')->where('id', $parent)->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('The native self-reference must remain enforced.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('FOREIGN KEY constraint failed', $error->getMessage());
            }
        }
        $this->assertSame(2, DB::table('lab_paid_works')->count());
    }

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
