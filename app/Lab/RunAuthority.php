<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\CanonicalJson;
use App\Models\LabAuthoritySession;
use App\Models\LabRunIntent;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Human approval and launch allocation; this is not a provider invoice meter. */
final readonly class RunAuthority
{
    public const SESSION_LIMIT_MICROUSD = 5_000_000;

    public function __construct(private OwnerIdentity $identity, private SessionPreflight $preflight) {}

    /** Owner-only metadata; reading never creates authority or releases allocation. */
    public function allocations(?string $before = null): array
    {
        $owner = $this->identity->requireOwner();
        $this->require($before === null || (Str::isUuid($before) && strtolower($before) === $before));
        $session = LabAuthoritySession::query()->where('owner_user_id', $owner->id)->first();
        $allocated = $session?->allocated_microusd ?? 0;
        $this->require($allocated >= 0 && $allocated <= self::SESSION_LIMIT_MICROUSD);
        $intents = [];
        $next = null;
        if ($session instanceof LabAuthoritySession) {
            $rows = LabRunIntent::query()->where('authority_session_id', $session->id)
                ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
                ->orderByDesc('id')->limit(21)
                ->get(['id', 'action', 'status', 'cap_microusd', 'expires_at', 'consumed_at', 'revoked_at']);
            $page = $rows->take(20);
            $next = $rows->count() > 20 ? $page->last()?->id : null;
            $intents = $page->map(fn (LabRunIntent $intent): array => [
                'id' => $intent->id, 'action' => $intent->action, 'status' => $intent->status,
                'cap_microusd' => $intent->cap_microusd,
                'expires_at' => $intent->expires_at->toIso8601String(),
                'consumed_at' => $intent->consumed_at?->toIso8601String(),
                'revoked_at' => $intent->revoked_at?->toIso8601String(),
                'can_revoke' => $intent->revoked_at === null && in_array($intent->status, ['draft', 'approved', 'ready', 'running'], true),
            ])->values()->all();
        }

        return ['allocated_microusd' => $allocated, 'available_microusd' => self::SESSION_LIMIT_MICROUSD - $allocated,
            'session_limit_microusd' => self::SESSION_LIMIT_MICROUSD, 'intents' => $intents, 'next_before' => $next];
    }

    public function draft(RunScope $scope, string $capUsd): LabRunIntent
    {
        $owner = $this->identity->requireOwner();
        $cap = $this->parseCap($capUsd);
        // The logical Lab session survives cookie rotation, logout and refresh.
        // There is deliberately no browser-controlled reset/new-session seam.
        $session = LabAuthoritySession::query()->firstOrCreate(['owner_user_id' => $owner->id]);
        $intent = new LabRunIntent;
        $intent->forceFill([
            'id' => (string) Str::uuid7(), 'authority_session_id' => $session->id,
            'action' => $scope->action, 'resource' => $scope->resource, 'payload' => $scope->payload,
            'cap_microusd' => $cap, 'expires_at' => now()->addMinutes(15)->startOfSecond(), 'status' => 'draft',
        ]);
        $intent->forceFill(['digest' => $this->digest($intent, $session)])->save();

        return $intent;
    }

    public function approve(string $id, string $expectedDigest): LabRunIntent
    {
        $owner = $this->identity->requireOwner();

        return $this->locked($id, function (LabRunIntent $intent, LabAuthoritySession $session) use ($owner, $expectedDigest): LabRunIntent {
            $this->assertFrozen($intent, $session, $expectedDigest);
            $this->require($intent->status === 'draft' && $session->owner_user_id === $owner->id);
            $intent->forceFill(['status' => 'approved', 'approved_by' => $owner->id,
                'approved_digest' => $intent->digest, 'approved_at' => now()])->save();

            return $intent;
        });
    }

    public function go(string $id, string $expectedDigest, string $capUsd, string $confirmation): LabRunIntent
    {
        $owner = $this->identity->requireOwner();
        $cap = $this->parseCap($capUsd);

        return $this->locked($id, function (LabRunIntent $intent, LabAuthoritySession $session) use ($owner, $expectedDigest, $cap, $confirmation): LabRunIntent {
            $this->assertFrozen($intent, $session, $expectedDigest);
            $this->assertApproved($intent, $session);
            $this->require($intent->status === 'approved' && $session->owner_user_id === $owner->id
                && $confirmation === 'GO' && $cap === $intent->cap_microusd
                && $session->allocated_microusd + $cap <= self::SESSION_LIMIT_MICROUSD);
            $this->changeAllocation($session, $session->allocated_microusd + $cap);
            $intent->forceFill(['status' => 'ready', 'go_by' => $owner->id,
                'go_digest' => $intent->digest, 'go_at' => now()])->save();

            return $intent;
        });
    }

    /** The same reference is usable by a CLI; it cannot approve or expand it. */
    public function consume(string $id, RunScope $scope): LabRunIntent
    {
        return $this->locked($id, function (LabRunIntent $intent, LabAuthoritySession $session) use ($scope): LabRunIntent {
            $this->assertScope($intent, $session, $scope);
            $this->assertGo($intent, $session);
            $this->require($intent->status === 'ready' && $intent->consumed_at === null);
            $this->preflight->assertReady();
            // The conditional write also protects stores without FOR UPDATE.
            $updated = LabRunIntent::query()->whereKey($intent->id)->where('status', 'ready')->whereNull('consumed_at')
                ->update(['status' => 'running', 'consumed_at' => now(), 'updated_at' => now()]);
            $this->require($updated === 1);

            return $intent->refresh();
        });
    }

    public function assertActive(string $id, RunScope $scope, bool $forExecution = false): LabRunIntent
    {
        return $this->locked($id, function (LabRunIntent $intent, LabAuthoritySession $session) use ($scope, $forExecution): LabRunIntent {
            $this->assertScope($intent, $session, $scope);
            $this->assertGo($intent, $session);
            $this->require($intent->status === 'running' && $intent->consumed_at !== null);
            if ($forExecution) {
                $this->preflight->assertReadyForExecution();
            } else {
                $this->preflight->assertReady();
            }

            return $intent;
        });
    }

    public function revoke(string $id): LabRunIntent
    {
        $owner = $this->identity->requireOwner();

        return $this->locked($id, function (LabRunIntent $intent, LabAuthoritySession $session) use ($owner): LabRunIntent {
            $this->require($session->owner_user_id === $owner->id);
            if ($intent->status === 'ready' && $intent->consumed_at === null) {
                // Expiry stops execution, not free cancellation of an unused
                // reservation. Its amount must still be the exact approved cap.
                $this->require($intent->revoked_at === null
                    && $intent->cap_microusd > 0 && $intent->cap_microusd <= self::SESSION_LIMIT_MICROUSD
                    && preg_match('/^[a-f0-9]{64}$/D', $intent->digest) === 1
                    && hash_equals($intent->digest, $this->digest($intent, $session)));
                $this->assertGo($intent, $session);
                $this->changeAllocation($session, $session->allocated_microusd - $intent->cap_microusd);
            }
            $intent->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

            return $intent;
        });
    }

    /** Read permission data, never infer approval from a caller's labels. */
    public function approvedReference(string $id): LabRunIntent
    {
        return $this->locked($id, function (LabRunIntent $intent, LabAuthoritySession $session): LabRunIntent {
            $this->assertFrozen($intent, $session, $intent->digest);
            $this->assertGo($intent, $session);
            $this->require(in_array($intent->status, ['ready', 'running'], true));

            return $intent;
        });
    }

    public function claimAcpHost(string $id, RunScope $scope): LabRunIntent
    {
        $this->require($scope->action === 'acp.send');
        $reference = $this->approvedReference($id);
        $intent = $reference->status === 'ready' ? $this->consume($id, $scope) : $this->assertActive($id, $scope);
        $updated = LabRunIntent::query()->whereKey($id)->where('status', 'running')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->whereNull('acp_host_claimed_at')->update(['acp_host_claimed_at' => now(), 'updated_at' => now()]);
        $this->require($updated === 1);

        return $intent->refresh();
    }

    public function claimAcpPrompt(string $id, RunScope $scope): void
    {
        $this->require($scope->action === 'acp.send');
        $this->assertActive($id, $scope);
        $updated = LabRunIntent::query()->whereKey($id)->where('status', 'running')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->whereNotNull('acp_host_claimed_at')->whereNull('acp_prompt_claimed_at')
            ->update(['acp_prompt_claimed_at' => now(), 'updated_at' => now()]);
        $this->require($updated === 1);
    }

    public function parseCap(string $usd): int
    {
        if (! preg_match('/^(0|[1-5])(?:\.([0-9]{1,6}))?$/D', $usd, $parts)) {
            throw ValidationException::withMessages(['cap_usd' => 'Choose a positive USD cap of at most $5 with up to six decimal places.']);
        }
        $micro = (int) $parts[1] * 1_000_000 + (int) str_pad($parts[2] ?? '', 6, '0');
        if ($micro < 1 || $micro > self::SESSION_LIMIT_MICROUSD) {
            throw ValidationException::withMessages(['cap_usd' => 'Choose a positive USD cap of at most $5 with up to six decimal places.']);
        }

        return $micro;
    }

    private function locked(string $id, Closure $operation): LabRunIntent
    {
        $this->require(Str::isUuid($id) && strtolower($id) === $id);
        $sessionId = LabRunIntent::query()->whereKey($id)->value('authority_session_id');

        return DB::transaction(function () use ($id, $sessionId, $operation): LabRunIntent {
            if (DB::connection()->getDriverName() === 'sqlite') {
                // SQLite ignores FOR UPDATE. Acquire its writer reservation
                // before reading a snapshot that a competing GO could change.
                // This leaves allocation and timestamps untouched; native
                // transaction rollback/retries and the conditional write stay.
                DB::table('lab_authority_sessions')->where('id', $sessionId)
                    ->update(['allocated_microusd' => DB::raw('allocated_microusd')]);
            }
            $session = LabAuthoritySession::query()->whereKey($sessionId)->lockForUpdate()->first();
            $intent = LabRunIntent::query()->whereKey($id)->lockForUpdate()->first();
            $this->require($session instanceof LabAuthoritySession && $intent instanceof LabRunIntent
                && $intent->authority_session_id === $session->id
                && $session->allocated_microusd >= 0 && $session->allocated_microusd <= self::SESSION_LIMIT_MICROUSD);
            $owner = User::query()->find($session->owner_user_id);
            $this->require($owner instanceof User && $this->identity->matches($owner));

            return $operation($intent, $session);
        }, 3);
    }

    private function changeAllocation(LabAuthoritySession $session, int $next): void
    {
        $this->require($next >= 0 && $next <= self::SESSION_LIMIT_MICROUSD);
        $updated = LabAuthoritySession::query()->whereKey($session->id)
            ->where('allocated_microusd', $session->allocated_microusd)
            ->update(['allocated_microusd' => $next, 'updated_at' => now()]);
        $this->require($updated === 1);
        $session->forceFill(['allocated_microusd' => $next]);
    }

    private function assertScope(LabRunIntent $intent, LabAuthoritySession $session, RunScope $scope): void
    {
        $this->assertFrozen($intent, $session, $intent->digest);
        $this->require($intent->action === $scope->action && $intent->resource === $scope->resource
            && hash_equals(CanonicalJson::encode($intent->payload), CanonicalJson::encode($scope->payload)));
    }

    private function assertFrozen(LabRunIntent $intent, LabAuthoritySession $session, string $expectedDigest): void
    {
        $this->require($intent->revoked_at === null && $intent->expires_at->isFuture()
            && $intent->cap_microusd > 0 && $intent->cap_microusd <= self::SESSION_LIMIT_MICROUSD
            && preg_match('/^[a-f0-9]{64}$/D', $intent->digest) === 1
            && hash_equals($intent->digest, $expectedDigest)
            && hash_equals($intent->digest, $this->digest($intent, $session)));
    }

    private function assertApproved(LabRunIntent $intent, LabAuthoritySession $session): void
    {
        $this->require($intent->approved_by === $session->owner_user_id && $intent->approved_at !== null
            && is_string($intent->approved_digest) && hash_equals($intent->digest, $intent->approved_digest));
    }

    private function assertGo(LabRunIntent $intent, LabAuthoritySession $session): void
    {
        $this->assertApproved($intent, $session);
        $this->require($intent->go_by === $session->owner_user_id && $intent->go_at !== null
            && is_string($intent->go_digest) && hash_equals($intent->digest, $intent->go_digest)
            && $session->allocated_microusd >= $intent->cap_microusd);
    }

    private function digest(LabRunIntent $intent, LabAuthoritySession $session): string
    {
        return hash('sha256', CanonicalJson::encode([
            'intent' => $intent->id, 'session' => $session->id, 'owner' => $session->owner_user_id,
            'action' => $intent->action, 'resource' => $intent->resource, 'payload' => $intent->payload,
            'cap_microusd' => $intent->cap_microusd, 'expires_at' => $intent->expires_at->getTimestamp(),
        ]));
    }

    private function require(bool $allowed): void
    {
        if (! $allowed) {
            throw new PaidRunNotAuthorised;
        }
    }
}
