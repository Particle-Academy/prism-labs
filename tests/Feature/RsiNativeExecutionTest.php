<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkLaneExecutor;
use App\Benchmarks\BenchmarkWork;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\FrozenTestDefinition;
use App\Benchmarks\RsiCostAdmission;
use App\Benchmarks\RsiLaneProfile;
use App\Jobs\CallTheRunJob;
use App\Jobs\ScoreLaneJob;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Models\LabRunIntent;
use App\Models\User;
use Closure;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunIdentity;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Usage;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\Concerns\FrozenTestFixture;
use Tests\TestCase;

final class RsiNativeExecutionTest extends TestCase
{
    use ApprovesBenchmarks;
    use FrozenTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // Native admission must COMMIT, not hide inside RefreshDatabase's outer
        // transaction. Each application owns this in-memory DB, which vanishes
        // on teardown; no operational database or migration rollback is used.
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
        $this->prepareBenchmarkAuthority();
        config(['prism-harness.pricing' => ['anthropic' => ['claude-sonnet-5' => [
            'inputPerMTok' => 0, 'outputPerMTok' => 10000, 'toolOverheadTokens' => 286,
        ]]]]);
        Queue::fake();
    }

    private function approvedTest(?Closure $change = null): BenchmarkSpec
    {
        $input = self::definitionInput();
        $input['fixtures'] = [];
        $reference = app(RsiLaneProfile::class)->installedReference();
        $input['lanes'][0]['package_ref'] = $reference;
        $input['lanes'][0]['harness_ref'] = $reference;
        $input['lanes'][0]['mode'] = RsiLaneProfile::MODE;
        $input['lanes'][0]['bounds']['limits'] = ['max_output_tokens' => 10, 'max_tool_calls' => 0];
        if ($change !== null) {
            $input = $change($input);
        }
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'], ['value' => '["anthropic:claude-sonnet-5"]']);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            $designer = app(BenchmarkDesigner::class);
            $draft = $designer->draftTest('Native bounded test', FrozenTestDefinition::fromArray($input));

            return $designer->approve($designer->requestApproval($draft));
        } finally {
            auth('web')->logout();
        }
    }

    private function dispatchedTest(?Closure $change = null): BenchmarkRun
    {
        $spec = $this->approvedTest($change);

        return $this->withApprovedLabRun('benchmarks.launch', [], function () use ($spec): BenchmarkRun {
            $run = app(BenchmarkDesigner::class)->launch($spec);
            app(BenchmarkWorkflow::class)->dispatch($run);

            return $run->fresh();
        }, $spec->id);
    }

    private function execute(BenchmarkLane $lane): array
    {
        $this->beginBenchmarkJob('default');
        $workflow = WorkflowRun::query()->findOrFail($lane->workflow_run_id);
        $node = app(FancyFlowManager::class)->toGraph($workflow->schema)->nodes[0];

        return app(BenchmarkLaneExecutor::class)->execute(new ExecutionContext($node, [], fn () => null, run: new RunIdentity($workflow->run_key)));
    }

    private function nativeWork(BenchmarkLane $lane, Closure $operation): mixed
    {
        $this->beginBenchmarkJob('default');

        return app(PaidWorkRegistry::class)->run($lane->paid_work_id, 'benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane), function () use ($lane, $operation): mixed {
            $lane->forceFill(['status' => 'running', 'started_at' => now()])->save();

            return $operation(app(RsiCostAdmission::class)->context($lane));
        });
    }

    private function invokeNativeCall($context): void
    {
        (new BudgetedCalls(CallBudget::forRun($context, 'anthropic', 'claude-sonnet-5', [])))
            ->text(Prism::text()->using('anthropic', 'claude-sonnet-5')->withPrompt('Synthetic bounded call.'), $context, static function (): void {}, static function (): void {});
    }

    public function test_actual_native_session_and_flow_work_binding_settle_unjudged_without_scoring_or_exporting_content(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $private = 'PRIVATE_SYNTHETIC_OUTPUT_NOT_AN_EXPORTED_ARTIFACT';
        $fake = Prism::fake([TextResponseFake::make()->withText($private)->withUsage(new Usage(3, 2, cost: .04))]);
        $result = $this->execute($lane);
        $this->assertTrue($result['ok']);
        $this->assertSame('completed', $lane->fresh()->status);
        $this->assertSame('unjudged', $lane->fresh()->proof['judgment']);
        $this->assertSame(hash('sha256', $private), $lane->fresh()->proof['output_sha256']);
        $this->assertNull($lane->fresh()->proof['instrument_results']);
        $this->assertNull($lane->fresh()->score);
        $this->assertStringNotContainsString($private, json_encode($lane->fresh()->proof));
        $this->assertFalse($lane->fresh()->proof['artifact_content_captured']);
        $this->assertSame(1, DB::table('lab_call_reservations')->count());
        $call = DB::table('lab_call_reservations')->sole();
        $this->assertSame($run->approved_run_id, $call->intent_id);
        $this->assertSame($lane->paid_work_id, $call->paid_work_id);
        $this->assertSame($run->id, $call->root_run_id);
        $this->assertSame(100000, (int) $call->maximum_microusd);
        $this->assertSame(40000, (int) $call->charged_microusd);
        $this->assertTrue((bool) $call->usage_known);
        $this->assertSame('completed', $call->outcome);
        $fake->assertCallCount(1);
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame([], $requests[0]->tools());
        });
        Queue::assertNotPushed(ScoreLaneJob::class);
        Queue::assertNotPushed(CallTheRunJob::class);
        $this->assertSame(0, DB::table('benchmark_scores')->count());
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_frozen_inputs_stay_visible_and_make_zero_provider_calls(string $case): void
    {
        $run = $this->dispatchedTest(function (array $input) use ($case): array {
            match ($case) {
                'language' => $input['lanes'][0]['language'] = 'python',
                'reference' => $input['lanes'][0]['package_ref']['sha256'] = str_repeat('0', 64),
                'mode' => $input['lanes'][0]['mode'] = 'benchmark',
                'bound' => $input['lanes'][0]['bounds']['limits']['max_input_tokens'] = 1000,
                'fixture' => $input['fixtures'] = self::definitionInput()['fixtures'],
            };

            return $input;
        });
        $fake = Prism::fake([]);
        $lane = $run->lanes()->firstOrFail();
        $this->assertFalse($this->execute($lane)['ok']);
        $this->assertSame('unsupported', $lane->fresh()->proof['classification']);
        $this->assertSame('unjudged', $lane->fresh()->proof['judgment']);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
        $fake->assertCallCount(0);
    }

    public static function unsupported(): array
    {
        return array_map(fn ($s) => [$s], ['language', 'reference', 'mode', 'bound', 'fixture']);
    }

    public function test_absent_trusted_prices_refuse_before_a_provider_call_or_durable_reservation(): void
    {
        config(['prism-harness.pricing' => []]);
        $run = $this->dispatchedTest();
        $fake = Prism::fake([]);
        $lane = $run->lanes()->firstOrFail();
        $this->assertFalse($this->execute($lane)['ok']);
        $this->assertSame('budget_unpriced', $lane->fresh()->proof['code']);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
        $fake->assertCallCount(0);
    }

    public function test_duplicate_native_delivery_cannot_buy_a_second_call(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withText('done')->withUsage(new Usage(1, 1, cost: .04))]);
        $this->assertTrue($this->execute($lane)['ok']);
        $this->assertTrue($this->execute($lane->fresh())['ok']);
        $fake->assertCallCount(1);
        $this->assertSame(1, DB::table('lab_call_reservations')->count());
    }

    public function test_outer_transaction_is_refused_before_provider_or_reservation_that_could_roll_back(): void
    {
        $run = $this->dispatchedTest();
        $fake = Prism::fake([]);
        $lane = $run->lanes()->firstOrFail();
        DB::transaction(fn () => $this->execute($lane));
        $fake->assertCallCount(0);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
        $this->assertSame('uncommitted_cost_reservation', $lane->fresh()->proof['code'] ?? null);
    }

    public function test_frozen_lane_binding_cannot_be_changed_behind_its_paid_work_reference(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $definition = $lane->frozen_test_lane;
        $definition['bounds']['max_calls']++;
        $lane->forceFill(['frozen_test_lane' => $definition])->save();
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->execute($lane);
        } finally {
            $fake->assertCallCount(0);
            $this->assertSame(0, DB::table('lab_call_reservations')->count());
        }
    }

    public function test_same_work_cannot_reset_the_native_call_limit_by_constructing_another_context(): void
    {
        $run = $this->dispatchedTest(function (array $input): array {
            $input['lanes'][0]['bounds']['max_calls'] = 1;

            return $input;
        });
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withUsage(new Usage(1, 1, cost: .04)), TextResponseFake::make()->withUsage(new Usage(1, 1, cost: .04))]);
        $this->nativeWork($lane, function ($context) use ($lane): void {
            $this->invokeNativeCall($context);
            $second = app(RsiCostAdmission::class)->context($lane);
            try {
                $this->invokeNativeCall($second);
                $this->fail('A fresh in-memory ledger bypassed the durable call limit.');
            } catch (RunNotPermitted $e) {
                $this->assertSame('call_limit_exhausted', $e->code());
            }
        });
        $fake->assertCallCount(1);
        $this->assertSame(1, DB::table('lab_call_reservations')->count());
    }

    public function test_native_settlement_is_idempotent_and_conflicting_replay_cannot_refund_spend(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        Prism::fake([TextResponseFake::make()->withUsage(new Usage(1, 1, cost: .04))]);
        $this->nativeWork($lane, function ($context): void {
            $this->invokeNativeCall($context);
            $row = DB::table('lab_call_reservations')->sole();
            $context->observer->settled(new CallSettlement($row->call_id, .04, true, new Usage(1, 1, cost: .04), 'completed'), $context);
            try {
                $context->observer->settled(new CallSettlement($row->call_id, 0, true, null, 'completed'), $context);
                $this->fail('A conflicting settlement was accepted.');
            } catch (CallRefused) {
                $this->assertSame(40000, (int) DB::table('lab_call_reservations')->sole()->charged_microusd);
            }
        });
    }

    public function test_rsi_paid_work_can_never_be_turned_into_a_judge_or_commentary_producer(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $this->nativeWork($lane, function () use ($run, $lane): void {
            foreach ([
                fn () => BenchmarkWork::bindCommentary($run, 1),
                fn () => BenchmarkWork::bindScore($lane),
                fn () => app(PaidWorkRegistry::class)->bind('benchmark.score', $lane->id, BenchmarkWork::scorePayload($lane), $lane->paid_work_id),
                fn () => app(PaidWorkRegistry::class)->bind('benchmark.commentary', $run->id.':1', BenchmarkWork::commentaryPayload($run->id, 1), $run->paid_work_id),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('An otherwise authorized RSI producer created reviewer/commentary authority.');
                } catch (PaidRunNotAuthorised) {
                    $this->addToAssertionCount(1);
                }
            }
        });
        $this->assertSame(2, DB::table('lab_paid_works')->count());
    }

    public function test_two_fresh_native_ledgers_cannot_ignore_a_pending_call_under_the_same_allocation(): void
    {
        config(['prism-harness.pricing.anthropic.claude-sonnet-5.outputPerMTok' => 60000]);
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withText('held')->withUsage(new Usage(1, 1, cost: .04)), TextResponseFake::make()->withUsage(new Usage(1, 1, cost: .04))]);
        $this->nativeWork($lane, function ($context) use ($lane): void {
            $calls = new BudgetedCalls(CallBudget::forRun($context, 'anthropic', 'claude-sonnet-5', []));
            $stream = $calls->stream(Prism::text()->using('anthropic', 'claude-sonnet-5')->withPrompt('Synthetic pending call.'), $context, static function (): void {}, static function (): void {});
            $stream->rewind();
            $this->assertTrue($stream->valid());
            $pending = DB::table('lab_call_reservations')->sole();
            $this->assertNull($pending->settled_at);
            $this->assertSame(600000, (int) $pending->maximum_microusd);
            try {
                $this->invokeNativeCall(app(RsiCostAdmission::class)->context($lane));
                $this->fail('The second native ledger ignored a pending reservation.');
            } catch (RunNotPermitted $e) {
                $this->assertSame('account_exhausted', $e->code());
            } finally {
                unset($stream);
                gc_collect_cycles();
            }
        });
        $fake->assertCallCount(1);
        $this->assertSame(1, DB::table('lab_call_reservations')->count());
        $this->assertSame(600000, (int) DB::table('lab_call_reservations')->sole()->charged_microusd);
    }

    public function test_cached_native_context_must_reauthorize_after_its_approval_expires(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([]);
        $this->nativeWork($lane, function ($context) use ($run): void {
            LabRunIntent::query()->whereKey($run->approved_run_id)->update(['expires_at' => now()->subSecond()]);
            try {
                $this->invokeNativeCall($context);
                $this->fail('Cached observer authority survived expiry.');
            } catch (RunNotPermitted $e) {
                $this->assertSame('call_refused_by_host', $e->code());
            }
        });
        $fake->assertCallCount(0);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
    }

    public function test_an_observer_cannot_be_borrowed_by_a_foreign_native_root(): void
    {
        $lane = $this->dispatchedTest()->lanes()->firstOrFail();
        $fake = Prism::fake([]);
        $this->nativeWork($lane, function ($context): void {
            $foreign = RunContext::root('foreign-root', $context->budget, $context->pricing, $context->observer);
            try {
                $this->invokeNativeCall($foreign);
                $this->fail('Foreign root borrowed the authorized allocation.');
            } catch (RunNotPermitted $e) {
                $this->assertSame('allocation_identity_mismatch', $e->code());
            }
        });
        $fake->assertCallCount(0);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
    }

    public function test_a_forged_unknown_zero_settlement_cannot_release_a_live_native_reservation(): void
    {
        $lane = $this->dispatchedTest()->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withText('held')->withUsage(new Usage(1, 1, cost: .04))]);
        $this->nativeWork($lane, function ($context): void {
            $calls = new BudgetedCalls(CallBudget::forRun($context, 'anthropic', 'claude-sonnet-5', []));
            $stream = $calls->stream(Prism::text()->using('anthropic', 'claude-sonnet-5')->withPrompt('Synthetic pending call.'), $context, static function (): void {}, static function (): void {});
            $stream->rewind();
            $row = DB::table('lab_call_reservations')->sole();
            try {
                $context->observer->settled(new CallSettlement($row->call_id, 0, false, null, 'abandoned'), $context);
                $this->fail('Unknown settlement refunded unresolved work.');
            } catch (CallRefused) {
                $this->assertNull(DB::table('lab_call_reservations')->sole()->settled_at);
            } finally {
                unset($stream);
                gc_collect_cycles();
            }
        });
        $fake->assertCallCount(1);
        $this->assertSame(100000, (int) DB::table('lab_call_reservations')->sole()->charged_microusd);
    }

    public function test_abandoned_native_stream_charges_the_maximum_and_keeps_usage_unknown(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withText('Synthetic stream')->withUsage(new Usage(1, 1, cost: .04))]);
        $this->nativeWork($lane, function ($context): void {
            $calls = new BudgetedCalls(CallBudget::forRun($context, 'anthropic', 'claude-sonnet-5', []));
            $stream = $calls->stream(Prism::text()->using('anthropic', 'claude-sonnet-5')->withPrompt('Synthetic stream.'), $context, static function (): void {}, static function (): void {});
            $stream->rewind();
            $this->assertTrue($stream->valid());
            unset($stream);
            gc_collect_cycles();
        });
        $row = DB::table('lab_call_reservations')->sole();
        $this->assertSame('abandoned', $row->outcome);
        $this->assertSame(100000, (int) $row->charged_microusd);
        $this->assertFalse((bool) $row->usage_known);
        $fake->assertCallCount(1);
    }

    public function test_native_provider_failure_preserves_the_maximum_without_exporting_private_error_content(): void
    {
        $run = $this->dispatchedTest();
        $fake = new class extends PrismFake
        {
            public function text(Request $request): Response
            {
                $this->recorded[] = $request;
                throw new ConnectionException('PRIVATE_SYNTHETIC_FAILURE_CANARY');
            }
        };
        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(private PrismFake $fake) {}

            public function resolve(Provider|string $name, array $providerConfig = []): PrismFake
            {
                return $this->fake;
            }
        });
        $lane = $run->lanes()->firstOrFail();
        $this->assertFalse($this->execute($lane)['ok']);
        $this->assertSame('environment', $lane->fresh()->proof['classification']);
        $this->assertStringNotContainsString('PRIVATE_SYNTHETIC_FAILURE_CANARY', json_encode($lane->fresh()->proof));
        $row = DB::table('lab_call_reservations')->sole();
        $this->assertSame('failed', $row->outcome);
        $this->assertSame(100000, (int) $row->charged_microusd);
        $this->assertFalse((bool) $row->usage_known);
        $fake->assertCallCount(1);
    }
}
