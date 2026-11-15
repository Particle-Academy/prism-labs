<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompactionProbeRun;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaidCliAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Queue::fake();
    }

    #[DataProvider('boundedCommands')]
    public function test_paid_commands_refuse_missing_authority_before_provider_work(string $command, array $arguments): void
    {
        $this->artisan($command, $arguments)->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertNoWork();
    }

    #[DataProvider('boundedCommands')]
    public function test_paid_commands_refuse_a_forged_reference_without_authenticating_an_actor(string $command, array $arguments): void
    {
        $this->artisan($command, [...$arguments, '--approved-run' => '00000000-0000-4000-8000-000000000001'])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertGuest('web');
        $this->assertNoWork();
    }

    public static function boundedCommands(): array
    {
        return [['team:ask', ['question' => 'Owned fixture']], ['lab:test', []],
            ['compaction:probe', []], ['compaction:recall', []], ['compaction:summary-loss', []], ['lab:acp-agent', []]];
    }

    public function test_every_paid_cli_declares_an_approved_reference_and_no_actor_or_approval_bypass(): void
    {
        foreach (['team:ask', 'lab:test', 'compaction:probe', 'compaction:recall', 'compaction:summary-loss', 'lab:acp-agent'] as $name) {
            $definition = Artisan::all()[$name]->getDefinition();
            $this->assertTrue($definition->hasOption('approved-run'), $name.' has no explicit approved-run reference.');
            foreach (['approve', 'actor', 'owner', 'owner-id', 'approved-by', 'password', 'provision'] as $forbidden) {
                $this->assertFalse($definition->hasOption($forbidden), $name.' can impersonate or approve.');
            }
        }
    }

    private function assertNoWork(): void
    {
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, CompactionProbeRun::query()->count());
        $this->assertSame(0, LabRunIntent::query()->count());
        $this->assertSame(0, User::query()->count());
    }
}
