<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkLaneExecutor;
use App\Benchmarks\BenchmarkWork;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\CanonicalJson;
use App\Benchmarks\FrozenTestDefinition;
use App\Benchmarks\RsiCostAdmission;
use App\Benchmarks\RsiInstruments;
use App\Benchmarks\RsiLaneProfile;
use App\Benchmarks\RsiRunEvidence;
use App\Jobs\CallTheRunJob;
use App\Jobs\ScoreLaneJob;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
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
use Illuminate\Support\Str;
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
        $this->assertSame('unknown', $lane->fresh()->proof['instrument_results'][0]['status']);
        $this->assertSame('unsupported_instrument', $lane->fresh()->proof['instrument_results'][0]['reason']);
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

    public function test_owner_run_room_exports_only_bound_evidence_and_keeps_complete_frozen_review(): void
    {
        $private = 'PRIVATE_PROVIDER_RESULT_EXPORT_CANARY';
        $run = $this->dispatchedTest(function (array $input): array {
            $pin = array_column(app(RsiInstruments::class)->catalog(), null, 'id')['text.equals'];
            $input['instruments'] = [$pin];
            $input['assertions'] = [['id' => 'answer', 'instrument_id' => $pin['id'], 'expected' => 'expected public answer']];

            return $input;
        });
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withText($private)->withUsage(new Usage(3, 2, cost: .04))]);
        $this->execute($lane);
        $proof = $lane->fresh()->proof;
        $proof['private_transcript'] = $private;
        $proof['instrument_results'][0]['private_body'] = $private;
        $lane->forceFill(['proof' => $proof])->save();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $response = $this->getJson('/runs/'.$run->id.'/evidence?lane='.$lane->id)->assertOk();
        $response->assertJsonPath('run.judgment', 'unjudged')->assertJsonPath('run.artifact_content_captured', false)
            ->assertJsonPath('lanes.0.instruments.0.status', 'fail')->assertJsonPath('lanes.0.artifact.sha256', hash('sha256', $private))
            ->assertJsonPath('lanes.0.metrics.prompt_tokens', 3)->assertJsonPath('allocation.costs.known_charge_microusd', 40000);
        $this->assertStringNotContainsString($private, $response->getContent());
        $this->assertSame($run->spec->specification, $response->json('contract.specification'));
        $this->assertSame($run->spec->lane_matrix, $response->json('contract.lanes'));
        $this->assertSame($run->spec->budgets, $response->json('contract.budgets'));
        $this->assertSame($run->spec->approved_digest, $response->json('contract.approved_digest'));
        $this->get('/runs/'.$run->id)->assertOk();
        $this->get('/lab/benchmarks/specs/'.$run->benchmark_spec_id)->assertOk();
        foreach (['', '/file?path=private', '/media?path=private'] as $suffix) {
            $this->getJson('/lab/benchmarks/runs/'.$run->id.'/lanes/'.$lane->id.$suffix)->assertNotFound();
        }
        $this->getJson('/runs/'.$run->id.'/evidence?lane='.Str::uuid())->assertNotFound();
        $fake->assertCallCount(1);
        Queue::assertNotPushed(ScoreLaneJob::class);
        Queue::assertNotPushed(CallTheRunJob::class);
    }

    #[DataProvider('malformedInstrumentEvidence')]
    public function test_malformed_measurement_cannot_be_presented_as_a_passing_instrument(string $case): void
    {
        $run = $this->dispatchedTest(function (array $input): array {
            $pin = array_column(app(RsiInstruments::class)->catalog(), null, 'id')['text.equals'];
            $input['instruments'] = [$pin];
            $input['assertions'] = [['id' => 'answer', 'instrument_id' => $pin['id'], 'expected' => 'correct']];

            return $input;
        });
        $lane = $run->lanes()->firstOrFail();
        Prism::fake([TextResponseFake::make()->withText('correct')->withUsage(new Usage(1, 1, cost: .01))]);
        $this->execute($lane);
        $proof = $lane->fresh()->proof;
        match ($case) {
            'actual-pin' => $proof['instrument_results'][0]['actual_instrument']['sha256'] = str_repeat('0', 64),
            'reason' => $proof['instrument_results'][0]['reason'] = 'expectation_not_met',
            'bytes' => $proof['instrument_results'][0]['observed']['bytes'] = PHP_INT_MAX,
            'duplicate' => $proof['instrument_results'][] = $proof['instrument_results'][0],
        };
        $lane->forceFill(['proof' => $proof])->save();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $result = app(RsiRunEvidence::class)->present($run, null)['lanes'][0]['instruments'][0];
        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['observed']);
    }

    public static function malformedInstrumentEvidence(): array
    {
        return array_map(fn ($case) => [$case], ['actual-pin', 'reason', 'bytes', 'duplicate']);
    }

    public function test_run_evidence_requires_the_owner_and_does_not_borrow_a_foreign_work_binding(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $this->getJson('/runs/'.$run->id.'/evidence')->assertForbidden();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/runs/'.$run->id.'/evidence')->assertForbidden();
        $this->actingAs($this->authorityFixtureOwner, 'web')->getJson('/runs/'.$run->id.'/evidence')->assertOk();
        $lane->forceFill(['paid_work_id' => null])->save();
        $this->getJson('/runs/'.$run->id.'/evidence')->assertNotFound();
    }

    public function test_a_foreign_flow_reference_cannot_lend_its_progress_or_private_node_details(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $evidence = app(RsiRunEvidence::class);
        $this->assertSame($lane->workflow_run_id, $evidence->present($run, null)['lanes'][0]['flow']['id']);
        $flow = WorkflowRun::query()->findOrFail($lane->workflow_run_id);
        $schema = $flow->schema;
        $this->assertSame($lane->id, $schema['graph']['nodes'][0]['config']['lane_id']);
        $schema['graph']['nodes'][0]['config']['lane_id'] = (string) Str::uuid();
        $flow->forceFill(['schema' => $schema])->save();
        $this->assertNull($evidence->present($run, null)['lanes'][0]['flow']);
    }

    #[DataProvider('nativeUsageMeasurements')]
    public function test_native_token_measurements_are_separate_from_cost_certainty(int $input, int $output, ?float $cost, ?int $expectedInput): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        Prism::fake([TextResponseFake::make()->withText('synthetic response')->withUsage(new Usage($input, $output, cost: $cost))]);
        $result = $this->execute($lane);
        $this->assertTrue($result['ok']);
        $this->assertSame($expectedInput, $lane->fresh()->proof['metrics']['prompt_tokens']);
        $this->assertNull($lane->fresh()->proof['metrics']['known_charge_microusd']);
        $this->assertSame(100000, (int) DB::table('lab_call_reservations')->sole()->charged_microusd);
    }

    public static function nativeUsageMeasurements(): array
    {
        return [[3, 2, -1.0, 3], [0, 0, null, null]];
    }

    public function test_expired_and_revoked_history_stays_visible_without_authorizing_another_call(): void
    {
        $run = $this->dispatchedTest();
        $intent = LabRunIntent::query()->findOrFail($run->approved_run_id);
        $this->travelTo($intent->expires_at->addSecond());
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($intent->id);
        $this->getJson('/runs/'.$run->id.'/evidence')->assertOk()->assertJsonPath('allocation.revoked', true)
            ->assertJsonPath('allocation.expired', true)->assertJsonPath('allocation.costs.known_charge_microusd', null);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
        $this->travelBack();
    }

    public function test_a_different_spec_digest_cannot_claim_observed_evidence(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        Prism::fake([TextResponseFake::make()->withText('synthetic')->withUsage(new Usage(1, 1, cost: .01))]);
        $this->execute($lane);
        $proof = $lane->fresh()->proof;
        $this->assertSame($run->spec->digest, $proof['spec_digest']);
        $proof['spec_digest'] = str_repeat('0', 64);
        $lane->forceFill(['proof' => $proof])->save();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $evidence = app(RsiRunEvidence::class)->present($run, null)['lanes'][0];
        $this->assertSame('unknown', $evidence['classification']);
        $this->assertNull($evidence['artifact']['sha256']);
        $this->assertNull($evidence['metrics']['prompt_tokens']);
    }

    public function test_artifact_size_cannot_silently_round_in_the_browser(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['proof' => ['spec_digest' => $run->spec->digest, 'output_bytes' => PHP_INT_MAX]])->save();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $this->assertNull(app(RsiRunEvidence::class)->present($run, null)['lanes'][0]['artifact']['bytes']);
    }

    public function test_malformed_stored_flow_is_unknown_without_exporting_its_parser_failure(): void
    {
        $run = $this->dispatchedTest();
        $lane = $run->lanes()->firstOrFail();
        $flow = WorkflowRun::query()->findOrFail($lane->workflow_run_id);
        $schema = $flow->schema;
        $this->assertSame($lane->id, $schema['graph']['nodes'][0]['config']['lane_id']);
        $schema['graph']['nodes'][0]['config'] = 'PRIVATE_FLOW_PARSE_CANARY';
        $flow->forceFill(['schema' => $schema])->save();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $response = $this->getJson('/runs/'.$run->id.'/evidence')->assertOk()->assertJsonPath('lanes.0.flow', null);
        $this->assertStringNotContainsString('PRIVATE_FLOW_PARSE_CANARY', $response->getContent());
    }

    public function test_native_owner_transitions_require_the_exact_reviewed_digest_before_each_step(): void
    {
        $this->approvedTest();
        $draft = app(BenchmarkDesigner::class)->draftTest('Exact digest review', FrozenTestDefinition::fromArray(self::definitionInput()));
        $fake = Prism::fake([]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $this->postJson('/lab/benchmarks/'.$draft->id.'/request-approval', ['digest' => $draft->digest])->assertStatus(419);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->withSession(['_token' => 'owned-rsi-review-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-rsi-review-csrf');
        foreach ([['request-approval', 'draft', 'awaiting_approval'], ['approve', 'awaiting_approval', 'approved']] as [$action, $before, $after]) {
            $path = '/lab/benchmarks/'.$draft->id.'/'.$action;
            $this->postJson($path)->assertUnprocessable();
            $this->postJson($path, ['digest' => str_repeat('0', 64)])->assertConflict();
            $this->assertSame($before, $draft->fresh()->status);
            $this->post($path, ['digest' => $draft->digest])->assertRedirect('/lab/benchmarks/specs/'.$draft->id);
            $this->assertSame($after, $draft->fresh()->status);
        }
        $this->assertSame($draft->digest, $draft->fresh()->approved_digest);
        $fake->assertCallCount(0);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
    }

    public function test_native_contract_review_preserves_integers_above_javascript_safe_range(): void
    {
        $run = $this->dispatchedTest(function (array $input): array {
            $input['fixtures'] = [['id' => 'large-fixture', 'sha256' => str_repeat('e', 64), 'bytes' => 9007199254740993]];
            $input['assertions'][0]['expected'] = ['maximum' => PHP_INT_MAX];

            return $input;
        });
        $fake = Prism::fake([]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $response = $this->getJson('/runs/'.$run->id.'/evidence')->assertOk();
        $documents = $response->json('review_documents');
        $this->assertIsArray($documents);
        $this->assertStringContainsString('9007199254740993', $documents['fixtures']);
        $this->assertStringContainsString('9223372036854775807', $documents['assertions']);
        $this->assertStringContainsString('9007199254740993', $documents['complete']);
        $this->assertStringNotContainsString('9007199254740992', $documents['complete']);
        $this->assertSame(CanonicalJson::encode($response->json('contract')), $documents['complete']);
        $this->get('/lab/benchmarks/specs/'.$run->benchmark_spec_id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Lab/RsiSpecificationReview')->where('reviewDocuments', $documents));
        $this->get('/runs/'.$run->id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Lab/RsiRunRoom')->where('evidence.review_documents', $documents));
        $fake->assertCallCount(0);
        $this->assertSame(0, DB::table('lab_call_reservations')->count());
    }
}
