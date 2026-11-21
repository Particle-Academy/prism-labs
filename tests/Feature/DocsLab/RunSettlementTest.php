<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\DocsLab\LiveCostObserver;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\DocsLabRun;
use App\Models\LabAuthoritySession;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DocsLabLive;
use Tests\TestCase;
use Throwable;

/**
 * G-316: a used GO kept its whole cap reserved against the $5 session limit
 * forever (two runs that spent $0.26 and $0.28 left $1). A finished run is now
 * settled at its actual cost, and only the rest is released: once, and
 * whether the run passed, failed or was aborted.
 */
final class RunSettlementTest extends TestCase
{
    use DocsLabLive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLabLive();
    }

    private function allocated(LabRunIntent $intent): int
    {
        return (int) LabAuthoritySession::query()->whereKey($intent->authority_session_id)->value('allocated_microusd');
    }

    public function test_a_finished_run_releases_its_cap_minus_what_it_actually_spent(): void
    {
        $intent = $this->approveLive($this->liveInput(), '1');
        $this->assertSame(1_000_000, $this->allocated($intent), 'GO reserves the whole cap before any call.');
        $this->scriptLive();

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--judge' => true, '--approved-run' => $intent->id])->assertSuccessful();

        $spent = (int) DB::table('lab_call_reservations')->where('intent_id', $intent->id)->sum('charged_microusd');
        $this->assertGreaterThan(0, $spent);
        $intent->refresh();
        $this->assertSame('settled', $intent->status);
        $this->assertNotNull($intent->settled_at);
        $this->assertSame($spent, $intent->settled_microusd);
        // Released exactly reserved minus actual: the spend stays allocated.
        $this->assertSame($spent, $this->allocated($intent));
    }

    public function test_nothing_is_released_twice(): void
    {
        $intent = $this->approveLive($this->liveInput('S1', judge: false), '1');
        $this->scriptLive('S1');
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--approved-run' => $intent->id])->assertSuccessful();
        $after = $this->allocated($intent);

        app(RunAuthority::class)->settle($intent->id);
        $this->artisan('lab:settle-runs', ['--apply' => true])->expectsOutputToContain('Nothing to settle.')->assertSuccessful();

        $this->assertSame($after, $this->allocated($intent));
        $this->assertSame((int) $intent->refresh()->settled_microusd, $after);
    }

    public function test_a_settled_go_authorises_nothing_more(): void
    {
        $intent = $this->approveLive($this->liveInput('S1', judge: false), '1');
        $this->scriptLive('S1');
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--approved-run' => $intent->id])->assertSuccessful();

        $this->expectException(PaidRunNotAuthorised::class);
        app(RunAuthority::class)->approvedReference($intent->id);
    }

    public function test_a_run_the_cap_stopped_before_any_call_releases_everything(): void
    {
        $intent = $this->approveLive($this->liveInput(), '0.01');
        $this->scriptLive();

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--judge' => true, '--approved-run' => $intent->id])->assertFailed();

        $this->assertSame('settled', $intent->refresh()->status);
        $this->assertSame(0, $intent->settled_microusd);
        $this->assertSame(0, $this->allocated($intent));
    }

    public function test_an_aborted_run_is_settled_at_what_it_spent_before_it_stopped(): void
    {
        $intent = $this->approveLive($this->liveInput('S1', judge: false), '1');
        $this->scriptLive('S1');
        // Recording the first result fails after its model calls were made:
        // the artefact directory is a FILE, so the run aborts with an error.
        $blocker = $this->docsLabArtefacts.'-blocker';
        file_put_contents($blocker, 'not a directory');
        config(['docs-lab.artefacts' => $blocker]);

        try {
            $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--approved-run' => $intent->id])->run();
        } catch (Throwable) {
            // The abort itself is not under test; what it leaves allocated is.
        } finally {
            @unlink($blocker);
        }

        $spent = (int) DB::table('lab_call_reservations')->where('intent_id', $intent->id)->sum('charged_microusd');
        $this->assertGreaterThan(0, $spent, 'The run made calls before it aborted.');
        $this->assertSame('running', DocsLabRun::query()->sole()->status, 'The run aborted: it was never closed.');
        $this->assertSame('settled', $intent->refresh()->status);
        $this->assertSame($spent, $intent->settled_microusd);
        $this->assertSame($spent, $this->allocated($intent));
    }

    public function test_an_unsettled_call_is_kept_at_its_worst_case_and_a_past_go_reconciles_by_command(): void
    {
        // A GO used before settlement existed, whose process died with one
        // call reserved and never settled: the state the two real past GOs
        // are in, less the unsettled call.
        $input = $this->liveInput('S1', judge: false);
        $intent = $this->approveLive($input, '1');
        app(RunAuthority::class)->consume($intent->id, app(RunScopeFactory::class)->fromAction('cli.docs.run', 'lab:agent', $input));
        $work = LabPaidWork::query()->forceCreate(['intent_id' => $intent->id, 'kind' => 'docs.lab', 'work_key' => $intent->id,
            'payload' => [], 'payload_digest' => str_repeat('0', 64), 'claimed_at' => now(), 'finished_at' => now()]);
        foreach ([[120_000, 90_000], [200_000, null]] as [$maximum, $charged]) {
            DB::table('lab_call_reservations')->insert(['call_id' => Str::lower(Str::random(32)), 'intent_id' => $intent->id,
                'paid_work_id' => $work->id, 'work_digest' => str_repeat('0', 64), 'root_run_id' => 'r', 'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5', 'kind' => 'text', 'maximum_microusd' => $maximum, 'charged_microusd' => $charged,
                'settled_at' => $charged === null ? null : now(), 'outcome' => $charged === null ? null : 'completed',
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(1_000_000, $this->allocated($intent));

        $this->artisan('lab:settle-runs')->expectsOutputToContain('would settle')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(1_000_000, $this->allocated($intent), 'Listing changes nothing.');

        $this->artisan('lab:settle-runs', ['--apply' => true])->expectsOutputToContain('settled')->assertSuccessful();

        $this->assertSame(290_000, LiveCostObserver::spentFor($intent->id));
        $this->assertSame(290_000, $intent->refresh()->settled_microusd, 'An unsettled call counts at its worst case.');
        $this->assertSame(290_000, $this->allocated($intent));
    }

    public function test_a_run_with_unfinished_work_is_not_settled(): void
    {
        $input = $this->liveInput('S1', judge: false);
        $intent = $this->approveLive($input, '1');
        app(RunAuthority::class)->consume($intent->id, app(RunScopeFactory::class)->fromAction('cli.docs.run', 'lab:agent', $input));
        LabPaidWork::query()->forceCreate(['intent_id' => $intent->id, 'kind' => 'docs.lab', 'work_key' => $intent->id,
            'payload' => [], 'payload_digest' => str_repeat('0', 64), 'claimed_at' => now()]);

        try {
            app(RunAuthority::class)->settle($intent->id);
            $this->fail('Work still in flight must keep its whole cap.');
        } catch (PaidRunNotAuthorised) {
        }

        $this->assertSame('running', $intent->refresh()->status);
        $this->assertSame(1_000_000, $this->allocated($intent));
    }

    public function test_only_actions_metered_on_the_ledger_are_settleable(): void
    {
        $this->assertSame(['cli.docs.run'], RunAuthority::SETTLEABLE);
    }
}
