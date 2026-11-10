<?php

declare(strict_types=1);

namespace App\Lab;

use Closure;
use Illuminate\Console\Command;

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

            return $this->context->within($intent->id, $scope, $operation);
        } catch (PaidRunNotAuthorised) {
            return $this->refuse($command);
        } catch (SessionNotReady) {
            $command->error('Paid command refused: current Lab session preflight is not ready.');

            return Command::FAILURE;
        }
    }

    private function refuse(Command $command): int
    {
        $command->error('paid_call_not_authorised: Supply --approved-run with the owner\'s exact approved GO.');

        return Command::FAILURE;
    }
}
