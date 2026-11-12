<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\CanonicalJson;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use Closure;
use Illuminate\Support\Facades\DB;

/** Request/job-local context, always restored when a callback returns or throws. */
final class PaidRunContext
{
    private ?string $intentId = null;

    private ?RunScope $scope = null;

    private bool $executingJob = false;

    private ?LabPaidWork $work = null;

    private bool $workClaimed = false;

    public function __construct(private readonly RunAuthority $authority, private readonly RunScopeFactory $scopes) {}

    public function within(string $intentId, RunScope $scope, Closure $operation, bool $executingJob = false, ?LabPaidWork $work = null): mixed
    {
        $previousId = $this->intentId;
        $previousScope = $this->scope;
        $previousExecution = $this->executingJob;
        $previousWork = $this->work;
        $previousClaim = $this->workClaimed;
        $this->intentId = $intentId;
        $this->scope = $scope;
        $this->executingJob = $executingJob;
        $this->work = $work;
        $this->workClaimed = false;
        try {
            return $operation();
        } finally {
            $this->intentId = $previousId;
            $this->scope = $previousScope;
            $this->executingJob = $previousExecution;
            $this->work = $previousWork;
            $this->workClaimed = $previousClaim;
        }
    }

    /** @param list<string> $actions */
    public function requireActions(array $actions): LabRunIntent
    {
        if ($this->work !== null && ! $this->workClaimed) {
            return DB::transaction(function () use ($actions): LabRunIntent {
                AdmissionWriter::forIntent($this->intentId);
                // Keep intent/session locks through the one-time paid claim,
                // so revocation cannot fall between approval and reservation.
                $intent = $this->authorizedRun($actions);
                $claimed = LabPaidWork::query()->whereKey($this->work->id)->where('intent_id', $intent->id)
                    ->where('payload_digest', $this->work->payload_digest)->whereNull('claimed_at')->whereNull('finished_at')
                    ->update(['claimed_at' => now(), 'updated_at' => now()]);
                if ($claimed !== 1) {
                    throw new PaidRunNotAuthorised;
                }
                $this->workClaimed = true;

                return $intent;
            }, 3);
        }

        return $this->authorizedRun($actions);
    }

    /** Bind a direct service's actual arguments before any paid builder is made.
     * @param  list<string>  $actions
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $defaults  Only defaults the caller really applies.
     */
    public function requireInput(array $actions, array $actual, array $defaults = []): LabRunIntent
    {
        $intent = $this->authorizedRun($actions);
        $input = $intent->payload['input'];
        $expected = [];
        foreach ($actual as $key => $value) {
            if (! array_key_exists($key, $input) && ! array_key_exists($key, $defaults)) {
                throw new PaidRunNotAuthorised;
            }
            $expected[$key] = array_key_exists($key, $input) ? $input[$key] : $defaults[$key];
        }
        if (CanonicalJson::encode($actual) !== CanonicalJson::encode($expected)) {
            throw new PaidRunNotAuthorised;
        }

        return $this->requireActions($actions);
    }

    /** Check actual business inputs without claiming a free discovery retry. */
    public function authorizedWork(string $kind, string $key, array $payload): LabRunIntent
    {
        if ($this->work === null || $this->work->kind !== $kind || $this->work->work_key !== $key
            || $this->work->intent_id !== $this->intentId
            || CanonicalJson::encode($this->work->payload) !== CanonicalJson::encode($payload)) {
            throw new PaidRunNotAuthorised;
        }

        return $this->authorizedRun(PaidWorkRegistry::actionsFor($kind));
    }

    public function requireWork(string $kind, string $key, array $payload): LabRunIntent
    {
        $this->authorizedWork($kind, $key, $payload);

        return $this->requireActions(PaidWorkRegistry::actionsFor($kind));
    }

    /** A nested tool may spend only within the admitted business work kind. */
    public function requireBoundWork(string $kind): LabRunIntent
    {
        if ($this->work === null || $this->work->kind !== $kind) {
            throw new PaidRunNotAuthorised;
        }

        return $this->requireWork($kind, $this->work->work_key, $this->work->payload);
    }

    /** Validate a producer without claiming inference during free discovery/dispatch.
     * @param  list<string>  $actions
     */
    public function authorizedRun(array $actions): LabRunIntent
    {
        if ($this->intentId === null || $this->scope === null || ! in_array($this->scope->action, $actions, true)
            || ! is_array($input = $this->scope->payload['input'] ?? null)) {
            throw new PaidRunNotAuthorised;
        }
        // A delayed worker cannot reuse a stored green runtime fingerprint.
        $current = $this->scopes->fromAction($this->scope->action, $this->scope->resource, $input);

        return $this->authority->assertActive($this->intentId, $current, forExecution: $this->executingJob);
    }
}
