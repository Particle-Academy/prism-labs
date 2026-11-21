<?php

declare(strict_types=1);

namespace App\Lab;

use App\Models\LabRunIntent;
use Closure;
use Illuminate\Console\Command;
use Throwable;

final readonly class ApprovedCliRun
{
    public function __construct(private RunAuthority $authority, private RunScopeFactory $scopes, private PaidRunContext $context) {}

    /** @param array<string, mixed> $payload */
    public function run(Command $command, string $action, array $payload, Closure $operation): int
    {
        $id = $command->option('approved-run');
        if (! app()->environment('local') || ! is_string($id) || strlen($id) !== 36) {
            return $this->refuse($command);
        }
        try {
            $scope = $this->scopes->fromAction($action, 'lab:agent', $payload);
            $intent = $this->authority->consume($id, $scope);
            try {
                return $this->context->within($intent->id, $scope, $operation);
            } finally {
                // Passed, failed or aborted, the run is over: settle it at its
                // actual cost and release the rest of the cap.
                $this->settle($intent->id);
            }
        } catch (PaidRunNotAuthorised) {
            return $this->refuse($command);
        } catch (SessionNotReady) {
            $command->error('Paid command refused: current Lab session preflight is not ready.');

            return Command::FAILURE;
        }
    }

    /**
     * Settle when the action is settleable and its work is finished; otherwise
     * the GO keeps its whole cap, exactly as before. Never thrown from here: a
     * `finally` that throws would hide what the run itself did.
     */
    private function settle(string $intentId): void
    {
        $action = LabRunIntent::query()->whereKey($intentId)->value('action');
        if (! in_array($action, RunAuthority::SETTLEABLE, true)) {
            return;
        }
        try {
            $this->authority->settle($intentId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function refuse(Command $command): int
    {
        $command->error('paid_call_not_authorised: Supply --approved-run with the owner\'s exact approved GO.');

        return Command::FAILURE;
    }
}
