<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\DocsLab\Scenarios;
use App\DocsLab\ScriptedModel;
use App\Models\DocsLabRun;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Prism\Prism\ValueObjects\Usage;
use Tests\Concerns\DocsLabLive;
use Tests\TestCase;

/**
 * G-316 live lane: paid work only under the owner's exact GO, every call
 * reserved against the owner's cap, never in CI against a real provider (these
 * tests answer every call with Prism's fake).
 */
final class LiveLaneAuthorityTest extends TestCase
{
    use DocsLabLive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLabLive();
    }

    public function test_the_live_lane_is_refused_without_an_owner_approval(): void
    {
        $model = ScriptedModel::install([]);

        $this->artisan('lab:docs-run', ['--lane' => 'live'])->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--approved-run' => '00000000-0000-4000-8000-000000000001'])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();

        $this->assertSame([], $model->requests());
        $this->assertSame(0, DocsLabRun::query()->count());
        $this->assertSame(0, LabRunIntent::query()->count(), 'The CLI cannot create an approval request; only the owner can.');
        $this->assertGuest('web');
    }

    public function test_the_cli_declares_an_approved_reference_and_no_approval_bypass(): void
    {
        $definition = Artisan::all()['lab:docs-run']->getDefinition();
        $this->assertTrue($definition->hasOption('approved-run'));
        foreach (['approve', 'actor', 'owner', 'owner-id', 'approved-by', 'cap', 'cap-usd', 'password'] as $forbidden) {
            $this->assertFalse($definition->hasOption($forbidden), "lab:docs-run must not offer --{$forbidden}.");
        }
    }

    public function test_an_approved_live_run_records_every_scenario_and_settles_its_spend_under_the_cap(): void
    {
        $intent = $this->approveLive($this->liveInput(), '1');
        $model = $this->scriptLive();

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--judge' => true, '--approved-run' => $intent->id])->assertSuccessful();

        $run = DocsLabRun::query()->sole();
        $this->assertSame('live', $run->lane);
        $this->assertSame('passed', $run->status);
        $this->assertSame($intent->id, $run->intent_id);
        $this->assertSame(['S1', 'S2', 'S3', 'S4', 'R1', 'R2'], $run->results->pluck('scenario')->all());
        $this->assertSame(['pass'], $run->results->pluck('judge_verdict')->unique()->values()->all());
        // Every model call, builder and judge, was reserved and settled
        // against this intent before and after it reached the (fake) provider.
        $reservations = DB::table('lab_call_reservations')->where('intent_id', $intent->id)->get();
        $this->assertCount(count($model->requests()), $reservations);
        $this->assertTrue($reservations->every(fn ($r) => $r->settled_at !== null && $r->outcome === 'completed'));
        $this->assertSame(['claude-sonnet-5-5'], $reservations->pluck('model')->unique()->values()->all());
        $work = LabPaidWork::query()->where('intent_id', $intent->id)->sole();
        $this->assertSame('docs.lab', $work->kind);
        $this->assertNotNull($work->claimed_at);
        $this->assertNotNull($work->finished_at);
        $this->assertSame((int) $reservations->sum('charged_microusd'), $run->cost_microusd);
        $this->assertLessThanOrEqual($intent->cap_microusd, $run->cost_microusd);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic.com'));
    }

    public function test_the_spend_cap_stops_the_run_before_a_call_it_cannot_afford(): void
    {
        // $0.01 cannot cover one call's worst case (a 4096-token answer alone
        // is about $0.04 at the builder's list price), so the first call is
        // refused BEFORE the provider is asked, and the run stops there.
        $intent = $this->approveLive($this->liveInput(), '0.01');
        $model = $this->scriptLive();

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--judge' => true, '--approved-run' => $intent->id])->assertFailed();

        $run = DocsLabRun::query()->sole();
        $this->assertSame('stopped', $run->status);
        // The harness's own root budget (set to the approved cap) refuses
        // before the call reaches the ledger.
        $this->assertSame('refused:call_budget_exceeded', $run->error);
        $this->assertSame([], $model->requests(), 'No provider call may be made past the cap.');
        $this->assertSame(1, $run->results()->count(), 'The run stops at the first refusal.');
        $this->assertSame(0, DB::table('lab_call_reservations')->where('intent_id', $intent->id)->count());
        $this->assertSame(0, $run->cost_microusd);
    }

    public function test_the_cap_holds_across_scenarios_on_the_approved_ledger(): void
    {
        // Each scenario is its own harness run, so the harness budget alone
        // would allow the cap PER scenario. The ledger holds it across the
        // whole approval: here every call really costs about $0.40 (200k
        // input tokens at $2/M), so the run is refused part-way, by the
        // ledger, with the spend never above the cap plus one call's
        // already-admitted cost.
        $intent = $this->approveLive($this->liveInput(judge: false), '1');
        $turns = [];
        foreach (app(Scenarios::class)->all() as $scenario) {
            array_push($turns, ...ScriptedModel::fromScenario($scenario->scripted));
        }
        $model = ScriptedModel::install($turns, [], new Usage(200_000, 0));

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--approved-run' => $intent->id])->assertFailed();

        $run = DocsLabRun::query()->sole();
        $this->assertSame('stopped', $run->status);
        $this->assertSame('refused:account_exhausted', $run->error);
        $reservations = DB::table('lab_call_reservations')->where('intent_id', $intent->id)->get();
        $this->assertCount(count($model->requests()), $reservations, 'Every call that reached the provider was admitted first.');
        $this->assertLessThan(6, $run->results()->count());
        // Admission is checked against the cap BEFORE each call; the only
        // spend past the cap is a call already admitted under it.
        $admittedBeforeLast = (int) $reservations->slice(0, -1)->sum('charged_microusd') + (int) $reservations->last()->maximum_microusd;
        $this->assertLessThanOrEqual($intent->cap_microusd, $admittedBeforeLast);
    }

    public function test_a_go_is_used_once_and_only_for_the_plan_it_approved(): void
    {
        $intent = $this->approveLive($this->liveInput('S1'), '1');
        $model = $this->scriptLive('S1');

        // A different model is a different plan: refused before any call.
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--model' => 'claude-opus-5-5', '--approved-run' => $intent->id])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertSame([], $model->requests());

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--approved-run' => $intent->id])->assertSuccessful();
        // The same GO cannot buy a second run.
        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--approved-run' => $intent->id])
            ->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertSame(1, DocsLabRun::query()->count());
    }

    public function test_a_judge_fail_fails_the_scenario_even_when_every_structural_check_passes(): void
    {
        $intent = $this->approveLive($this->liveInput('S1'), '1');
        $this->scriptLive('S1', verdict: 'fail');

        $this->artisan('lab:docs-run', ['--lane' => 'live', '--scenario' => 'S1', '--judge' => true, '--approved-run' => $intent->id])->assertFailed();

        $result = DocsLabRun::query()->sole()->results->sole();
        $this->assertTrue($result->structural_passed);
        $this->assertSame('fail', $result->judge_verdict);
        $this->assertFalse($result->passed);
    }
}
