<?php

declare(strict_types=1);

namespace App\Lab;

use Closure;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunContext;

/** One trusted work binding; native child contexts retain this same observer. */
final readonly class NativeCostObserver implements CallCostObserver
{
    public function __construct(
        private string $intentId,
        private string $sessionId,
        private string $workId,
        private string $workDigest,
        private string $rootRunId,
        private string $provider,
        private string $model,
        private Closure $authorize,
    ) {}

    public function reserving(CallReservation $call, RunContext $run): void
    {
        // A nested DB commit is only a savepoint. Do not call a provider while
        // its durable reservation could still disappear in an outer rollback.
        if (DB::transactionLevel() !== 0) {
            throw new CallRefused('uncommitted_cost_reservation');
        }
        $this->require($run->rootRunId() === $this->rootRunId && $call->provider === $this->provider && $call->model === $this->model
            && in_array($call->kind, ['text', 'structured', 'stream'], true) && preg_match('/\A[a-f0-9]{32}\z/', $call->callId) === 1);
        $maximum = self::microusd($call->worstCaseUsd);
        DB::transaction(function () use ($call, $maximum): void {
            $this->lockAccount();
            $intent = ($this->authorize)();
            $this->require($intent->id === $this->intentId && $intent->authority_session_id === $this->sessionId);
            // Native host callback commits before BudgetedCalls can invoke the provider.
            $rows = DB::table('lab_call_reservations')->where('intent_id', $this->intentId)->get(['maximum_microusd', 'charged_microusd', 'settled_at']);
            $spent = 0;
            foreach ($rows as $row) {
                $charge = $row->settled_at === null ? $row->maximum_microusd : $row->charged_microusd;
                $this->require(is_numeric($charge) && (int) $charge >= 0);
                $spent += (int) $charge;
            }
            if ($spent + $maximum > $intent->cap_microusd) {
                throw new CallRefused('account_exhausted');
            }
            DB::table('lab_call_reservations')->insert([
                'call_id' => $call->callId, 'intent_id' => $this->intentId, 'paid_work_id' => $this->workId,
                'work_digest' => $this->workDigest, 'root_run_id' => $this->rootRunId,
                'provider' => $call->provider, 'model' => $call->model, 'kind' => $call->kind,
                'maximum_microusd' => $maximum, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function settled(CallSettlement $call, RunContext $run): void
    {
        $this->require($run->rootRunId() === $this->rootRunId && in_array($call->outcome, ['completed', 'failed', 'abandoned'], true));
        $charged = self::microusd($call->chargedUsd);
        DB::transaction(function () use ($call, $charged): void {
            $this->lockAccount();
            $row = DB::table('lab_call_reservations')->where('call_id', $call->callId)->lockForUpdate()->first();
            $this->require($row !== null && $row->intent_id === $this->intentId && $row->paid_work_id === $this->workId
                && hash_equals($row->work_digest, $this->workDigest) && $row->root_run_id === $this->rootRunId
                && $row->provider === $this->provider && $row->model === $this->model);
            // Settlement remains possible after revocation or expiry. Those stop NEW work;
            // they cannot erase the cost of a call that was already admitted.
            if ($row->settled_at !== null) {
                $this->require((int) $row->charged_microusd === $charged && (bool) $row->usage_known === $call->usageKnown && $row->outcome === $call->outcome);

                return;
            }
            $this->require($call->usageKnown || $charged >= (int) $row->maximum_microusd);
            DB::table('lab_call_reservations')->where('call_id', $call->callId)->whereNull('settled_at')->update([
                'charged_microusd' => $charged, 'usage_known' => $call->usageKnown, 'outcome' => $call->outcome,
                'settled_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    private function lockAccount(): void
    {
        // Match RunAuthority's session -> intent order. SQLite needs a write
        // reservation before the first read; PostgreSQL keeps the row locks.
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

    public static function microusd(float $usd): int
    {
        if (! is_finite($usd) || $usd < 0 || $usd > 1000000) {
            throw new CallRefused('invalid_call_cost');
        }

        // Round UP. Fractional microdollars must never disappear on repeated calls.
        return (int) ceil($usd * 1000000);
    }
}
