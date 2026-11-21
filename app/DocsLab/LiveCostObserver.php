<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Lab\NativeCostObserver;
use App\Models\LabRunIntent;
use Closure;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunContext;

/**
 * The live lane's spend cap: every model call is reserved against the OWNER'S
 * approved cap before the provider is called, and settled after.
 *
 * Same ledger and the same rules as NativeCostObserver (lab_call_reservations,
 * worst case reserved, rounded up, an unsettled call counted at its worst
 * case), bound to one approved intent and its one paid-work row. It differs
 * only in not knowing the harness's root run id in advance: DocsAgent::edit
 * opens its own root run per scenario, so the id is recorded from the call
 * rather than required to match one fixed beforehand. What a call may spend is
 * still decided by the intent, never by the run.
 *
 * The builder and the judge are the only models it admits, so a model swapped
 * in by configuration after approval is refused at the first call.
 */
final readonly class LiveCostObserver implements CallCostObserver
{
    /** @param list<string> $models */
    public function __construct(
        private string $intentId,
        private string $sessionId,
        private string $workId,
        private string $workDigest,
        private string $provider,
        private array $models,
        private Closure $authorize,
    ) {}

    public function reserving(CallReservation $call, RunContext $run): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new CallRefused('uncommitted_cost_reservation');
        }
        $this->require($call->provider === $this->provider && in_array($call->model, $this->models, true)
            && in_array($call->kind, ['text', 'structured'], true) && preg_match('/\A[a-f0-9]{32}\z/', $call->callId) === 1);
        $maximum = NativeCostObserver::microusd($call->worstCaseUsd);
        DB::transaction(function () use ($call, $run, $maximum): void {
            $this->lockAccount();
            $intent = ($this->authorize)();
            $this->require($intent instanceof LabRunIntent && $intent->id === $this->intentId && $intent->authority_session_id === $this->sessionId);
            if ($this->spent() + $maximum > $intent->cap_microusd) {
                throw new CallRefused('account_exhausted');
            }
            DB::table('lab_call_reservations')->insert([
                'call_id' => $call->callId, 'intent_id' => $this->intentId, 'paid_work_id' => $this->workId,
                'work_digest' => $this->workDigest, 'root_run_id' => substr($run->rootRunId(), 0, 128),
                'provider' => $call->provider, 'model' => $call->model, 'kind' => $call->kind,
                'maximum_microusd' => $maximum, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function settled(CallSettlement $call, RunContext $run): void
    {
        $this->require(in_array($call->outcome, ['completed', 'failed', 'abandoned'], true));
        $charged = NativeCostObserver::microusd($call->chargedUsd);
        DB::transaction(function () use ($call, $charged): void {
            $this->lockAccount();
            $row = DB::table('lab_call_reservations')->where('call_id', $call->callId)->lockForUpdate()->first();
            $this->require($row !== null && $row->intent_id === $this->intentId && $row->paid_work_id === $this->workId
                && hash_equals($row->work_digest, $this->workDigest) && $row->provider === $this->provider);
            if ($row->settled_at !== null) {
                $this->require((int) $row->charged_microusd === $charged && $row->outcome === $call->outcome);

                return;
            }
            // Unknown usage is charged at the worst case: a cap that forgets a
            // call because its price is unknown is not a cap.
            $this->require($call->usageKnown || $charged >= (int) $row->maximum_microusd);
            DB::table('lab_call_reservations')->where('call_id', $call->callId)->whereNull('settled_at')->update([
                'charged_microusd' => $charged, 'usage_known' => $call->usageKnown, 'outcome' => $call->outcome,
                'settled_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** What the intent has spent: settled calls at their charge, unsettled ones at their worst case. */
    public function spent(): int
    {
        return self::spentFor($this->intentId);
    }

    public static function spentFor(string $intentId): int
    {
        $spent = 0;
        foreach (DB::table('lab_call_reservations')->where('intent_id', $intentId)->get(['maximum_microusd', 'charged_microusd', 'settled_at']) as $row) {
            $spent += (int) ($row->settled_at === null ? $row->maximum_microusd : $row->charged_microusd);
        }

        return $spent;
    }

    private function lockAccount(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('lab_authority_sessions')->where('id', $this->sessionId)->update(['allocated_microusd' => DB::raw('allocated_microusd')]);
        }
        $session = DB::table('lab_authority_sessions')->where('id', $this->sessionId)->lockForUpdate()->first();
        $intent = DB::table('lab_run_intents')->where('id', $this->intentId)->lockForUpdate()->first();
        $this->require($session !== null && $intent !== null && $intent->authority_session_id === $session->id);
    }

    private function require(bool $allowed): void
    {
        if (! $allowed) {
            throw new CallRefused('allocation_identity_mismatch');
        }
    }
}
