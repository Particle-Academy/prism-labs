<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\Lab\RunAuthority;
use App\Models\DocsLabRun;
use App\Models\LabAuthoritySession;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\DocsLabLive;
use Tests\TestCase;

/**
 * `lab:approve-from-ftq`: the owner's Genie ForceTheQuestion answer recorded
 * as a GO through the SAME RunAuthority steps the page takes. Every existing
 * check must still hold on the GO it records. Fakes only; no provider is
 * reached.
 */
final class FtqOwnerApprovalTest extends TestCase
{
    use DocsLabLive;

    private const QUESTION = 'q_f8c85551-e268-46d5-8ee3-690885acaa56';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLabLive();
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
    }

    private function approveFromFtq(array $options = []): PendingCommand
    {
        return $this->artisan('lab:approve-from-ftq', [
            '--question' => self::QUESTION, '--answer' => 'You: approved', '--action' => 'cli.docs.run',
            '--cap-usd' => '2', '--scenario' => 'S1', '--judge' => true, ...$options,
        ]);
    }

    private function recorded(): LabRunIntent
    {
        return LabRunIntent::query()->sole();
    }

    public function test_a_recorded_go_lets_exactly_one_run_and_a_second_use_is_refused(): void
    {
        $this->approveFromFtq()->expectsOutputToContain('owner via Genie FTQ')->assertSuccessful();
        $intent = $this->recorded();

        $this->assertSame('ready', $intent->status);
        $this->assertSame('cli.docs.run', $intent->action);
        $this->assertSame(2_000_000, $intent->cap_microusd);
        $this->assertSame(RunAuthority::VIA_FTQ, $intent->approved_via);
        $this->assertSame(self::QUESTION, $intent->owner_question_id);
        $this->assertSame('You: approved', $intent->owner_answer);
        $this->assertSame((int) config('lab_authority.owner_user_id'), $intent->approved_by);
        $this->assertSame((int) config('lab_authority.owner_user_id'), $intent->go_by);
        $this->assertSame(2_000_000, LabAuthoritySession::query()->sole()->allocated_microusd, 'The cap is reserved, as at the page.');
        $this->assertTrue($intent->expires_at->equalTo(now()->addMinutes(15)->startOfSecond()));
        $this->assertGuest('web');

        $this->scriptLive('S1');
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--approved-run' => $intent->id])->assertSuccessful();
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--approved-run' => $intent->id])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertSame(1, DocsLabRun::query()->count());
        $this->assertSame($intent->id, DocsLabRun::query()->sole()->intent_id);
    }

    public function test_a_go_for_one_plan_is_refused_for_another(): void
    {
        $this->approveFromFtq()->assertSuccessful();
        $intent = $this->recorded();
        $model = $this->scriptLive('S1');

        foreach ([
            ['--scenario' => 'all', '--judge' => true],
            ['--scenario' => 'S1'],
            ['--scenario' => 'S1', '--judge' => true, '--model' => 'claude-opus-5-5'],
        ] as $other) {
            $this->artisan('lab:docs-run', ['--lane' => 'live', ...$other, '--approved-run' => $intent->id])
                ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        }
        $this->assertSame([], $model->requests());
        $this->assertSame(0, DocsLabRun::query()->count());
        $this->assertSame('ready', $intent->refresh()->status, 'A refused plan does not consume the GO.');
    }

    public function test_a_wrong_action_is_refused_at_approval_and_at_use(): void
    {
        $this->approveFromFtq(['--action' => 'cli.tests.run'])->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        $this->approveFromFtq(['--action' => 'agent.send'])->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        $this->assertSame(0, LabRunIntent::query()->count());

        // A recorded docs GO cannot be spent on a different paid CLI action.
        $this->approveFromFtq()->assertSuccessful();
        $intent = $this->recorded();
        $this->artisan('compaction:probe', ['--approved-run' => $intent->id])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertSame('ready', $intent->refresh()->status);
    }

    public function test_the_cap_is_required_and_bounded_and_is_the_cap_reserved(): void
    {
        $this->approveFromFtq(['--cap-usd' => ''])->expectsOutputToContain('--cap-usd is required')->assertFailed();
        $this->artisan('lab:approve-from-ftq', ['--question' => self::QUESTION, '--answer' => 'You: approved', '--action' => 'cli.docs.run'])
            ->expectsOutputToContain('--cap-usd is required')->assertFailed();
        foreach (['0', '5.000001', '6', '-1', '2usd', '1e1'] as $bad) {
            $this->approveFromFtq(['--cap-usd' => $bad])->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        }
        $this->assertSame(0, LabRunIntent::query()->count(), 'A refused cap leaves no draft behind.');

        // The session limit holds across GOs: $5 total, so $2 + $4 is refused.
        $this->approveFromFtq()->assertSuccessful();
        $this->approveFromFtq(['--question' => 'q_00000000-0000-4000-8000-000000000002', '--cap-usd' => '4'])
            ->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        $this->assertSame(1, LabRunIntent::query()->count());
        $this->assertSame(2_000_000, LabAuthoritySession::query()->sole()->allocated_microusd);
    }

    public function test_a_reused_question_id_is_refused(): void
    {
        $this->approveFromFtq()->assertSuccessful();
        $this->approveFromFtq()->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        // Even for a different plan or cap: the question bought one GO.
        $this->approveFromFtq(['--scenario' => 'S2', '--cap-usd' => '1'])->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        $this->assertSame(1, LabRunIntent::query()->count());
        $this->assertSame(2_000_000, LabAuthoritySession::query()->sole()->allocated_microusd);
    }

    public function test_a_recorded_go_expires_after_fifteen_minutes(): void
    {
        $this->approveFromFtq()->assertSuccessful();
        $intent = $this->recorded();
        $model = $this->scriptLive('S1');

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        // Keep the model listing fresh so the refusal below is the expiry, not the roster.
        $this->cacheModelRoster();
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--approved-run' => $intent->id])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertSame([], $model->requests());
        $this->assertSame(0, DocsLabRun::query()->count());
    }

    public function test_malformed_input_and_a_missing_owner_are_refused(): void
    {
        $this->approveFromFtq(['--question' => 'not-a-question'])->expectsOutputToContain('--question')->assertFailed();
        $this->approveFromFtq(['--question' => strtoupper(self::QUESTION)])->assertFailed();
        $this->approveFromFtq(['--answer' => '   '])->expectsOutputToContain('--answer')->assertFailed();
        $this->approveFromFtq(['--model' => 'claude-not-enabled'])->expectsOutputToContain('not enabled')->assertFailed();
        config(['lab_authority.owner_user_id' => null]);
        $this->approveFromFtq()->expectsOutputToContain('owner_go_not_recorded')->assertFailed();
        $this->assertSame(0, LabRunIntent::query()->count());
    }

    public function test_it_is_local_console_only(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->approveFromFtq()->expectsOutputToContain('local console')->assertFailed();
        $this->assertSame(0, LabRunIntent::query()->count());
        $this->assertArrayHasKey('lab:approve-from-ftq', Artisan::all());
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('ftq', strtolower($route->uri()), 'No HTTP route may record an FTQ GO.');
        }
    }

    public function test_the_page_path_is_unchanged_and_records_no_ftq_audit(): void
    {
        $intent = $this->approveLive($this->liveInput('S1'), '1');
        $this->assertSame('ready', $intent->status);
        $this->assertNull($intent->refresh()->approved_via);
        $this->assertNull($intent->owner_question_id);
        $this->assertNull($intent->owner_answer);
    }
}
