<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\FrozenTestDefinition;
use App\Consensus\ConsensusCoordinator;
use App\Http\Controllers\Lab\AcpController;
use App\Http\Controllers\Lab\AgentConversationController;
use App\Jobs\RunAcpTurnJob;
use App\Jobs\RunOverseerTurnJob;
use App\Lab\PaidRunContext;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\AcpTurn;
use App\Models\BenchmarkRun;
use App\Models\LabPaidWork;
use App\Models\OverseerTurn;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\Concerns\FrozenTestFixture;
use Tests\TestCase;

/** Exercise business entry points while observing the actual nested SQL transactions. */
final class AdmissionWriterBoundaryTest extends TestCase
{
    use ApprovesBenchmarks;
    use FrozenTestFixture;
    use RefreshDatabase;

    private function guarded(Closure $operation): mixed
    {
        // RefreshDatabase owns an ambient rollback transaction. Observe only
        // transactions the business operation starts, not fixture setup writes.
        $ambient = DB::transactionLevel();
        $armed = true;
        $writers = [];
        $reads = 0;
        Event::listen(TransactionBeginning::class, function ($event) use (&$writers, &$armed, $ambient): void {
            if ($armed && $event->connection->transactionLevel() > $ambient) {
                $writers[$event->connection->transactionLevel()] = false;
            }
        });
        $finished = function ($event) use (&$writers): void {
            foreach (array_keys($writers) as $level) {
                if ($level > $event->connection->transactionLevel()) {
                    unset($writers[$level]);
                }
            }
        };
        Event::listen(TransactionCommitted::class, $finished);
        Event::listen(TransactionRolledBack::class, $finished);
        DB::beforeExecuting(function (string $sql, array $bindings, $connection) use (&$armed, &$writers, &$reads, $ambient): void {
            $depth = $connection->transactionLevel();
            if (! $armed || $depth <= $ambient) {
                return;
            }
            $sql = strtolower(ltrim($sql));
            if (str_starts_with($sql, 'select')) {
                $reads++;
                $protected = false;
                foreach ($writers as $level => $writer) {
                    $protected = $protected || ($level <= $depth && $writer);
                }
                $this->assertTrue($protected, 'Admission read preceded its writer reservation: '.$sql);
            } elseif (preg_match('/^(update|insert|delete)\\b/', $sql) === 1) {
                $writers[$depth] = true;
            }
        });
        try {
            $result = $operation();
            $this->assertGreaterThan(0, $reads, 'The positive control must reach native admission reads.');

            return $result;
        } finally {
            $armed = false;
        }
    }

    public function test_consuming_human_authority_reserves_before_looking_up_the_session(): void
    {
        [$intent, $scope] = $this->approvedLabRun('benchmarks.compaction-probe', []);
        $consumed = $this->guarded(fn () => app(RunAuthority::class)->consume($intent->id, $scope));
        $this->assertSame('running', $consumed->status);
        $this->assertNotNull($consumed->consumed_at);
    }

    public static function specificationPaths(): array
    {
        return [['transition'], ['approve'], ['revise']];
    }

    #[DataProvider('specificationPaths')]
    public function test_frozen_contract_mutations_reserve_before_reading(string $path): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        $designer = app(BenchmarkDesigner::class);
        $definition = FrozenTestDefinition::fromArray(self::definitionInput());
        $draft = $designer->draftTest('Owned admission contract', $definition);
        if ($path === 'transition') {
            $next = $this->guarded(fn () => $designer->requestApproval($draft));
            $this->assertSame('awaiting_approval', $next->status);
        } elseif ($path === 'approve') {
            $waiting = $designer->requestApproval($draft);
            $next = $this->guarded(fn () => $designer->approve($waiting));
            $this->assertSame('approved', $next->status);
            $this->assertSame($owner->id, $next->approved_by);
        } else {
            $approved = $designer->approve($designer->requestApproval($draft));
            $next = $this->guarded(fn () => $designer->reviseTest($approved, $definition));
            $this->assertSame(2, $next->revision);
            $this->assertSame('draft', $next->status);
            $this->assertNull($next->approved_by);
            $this->assertSame('approved', $approved->fresh()->status);
        }
    }

    public function test_launch_reserves_before_its_frozen_contract_reads(): void
    {
        $spec = $this->approveBenchmarkSpec();
        $run = $this->withApprovedLabRun('benchmarks.launch', [],
            fn () => $this->guarded(fn () => app(BenchmarkDesigner::class)->launch($spec)), $spec->id);
        $this->assertSame($spec->id, $run->benchmark_spec_id);
        $this->assertNotNull($run->paid_work_id);
    }

    public function test_root_production_reserves_before_scope_and_work_lookups(): void
    {
        $this->withBenchmarkRun(function (BenchmarkRun $run): void {
            $work = LabPaidWork::query()->findOrFail($run->paid_work_id);
            $result = $this->guarded(fn () => app(PaidWorkRegistry::class)->produce(
                $work->id, $work->kind, $work->work_key, $work->payload, fn () => 'owned-root'));
            $this->assertSame('owned-root', $result);
            $this->assertNotNull($work->fresh()->claimed_at);
        });
    }

    public function test_paid_context_reserves_before_rebuilding_a_benchmark_scope(): void
    {
        $this->withBenchmarkRun(function (BenchmarkRun $run): void {
            $work = LabPaidWork::query()->findOrFail($run->paid_work_id);
            $scope = app(RunScopeFactory::class)->fromAction('benchmarks.launch', $run->benchmark_spec_id, []);
            $intent = app(PaidRunContext::class)->within($work->intent_id, $scope,
                fn () => $this->guarded(fn () => app(PaidRunContext::class)->requireActions(['benchmarks.launch'])), work: $work);
            $this->assertSame($work->intent_id, $intent->id);
            $this->assertNotNull($work->fresh()->claimed_at);
        });
    }

    public function test_acp_enqueue_reserves_before_duplicate_turn_reads(): void
    {
        Queue::fake();
        $response = $this->withApprovedLabRun('acp.send', ['prompt' => 'Owned ACP admission'],
            fn () => $this->guarded(fn () => app(AcpController::class)
                ->send(Request::create('/lab/acp', 'POST', ['prompt' => 'Owned ACP admission']))));
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(1, AcpTurn::query()->count());
        Queue::assertPushed(RunAcpTurnJob::class, 1);
    }

    public function test_consensus_producer_reserves_before_admission_reads(): void
    {
        $run = $this->withApprovedLabRun('consensus.collect', ['question' => 'Owned consensus admission', 'evidence' => 'Owned brief'],
            fn () => $this->guarded(fn () => app(ConsensusCoordinator::class)
                ->begin('Owned consensus admission', ['brief' => 'Owned brief'])));
        $this->assertSame('collecting', $run->status);
        $this->assertNotNull($run->paid_work_id);
        $this->assertNull(LabPaidWork::query()->findOrFail($run->paid_work_id)->claimed_at);
    }

    public function test_overseer_producer_reserves_before_admission_reads(): void
    {
        Queue::fake();
        $response = $this->withApprovedLabRun('agent.send', ['message' => 'Owned Overseer admission'],
            fn () => $this->guarded(fn () => app(AgentConversationController::class)
                ->send(Request::create('/lab/agent', 'POST', ['message' => 'Owned Overseer admission']))));
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(1, OverseerTurn::query()->count());
        Queue::assertPushed(RunOverseerTurnJob::class, 1);
    }
}
