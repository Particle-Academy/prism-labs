<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\CanonicalJson;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;

final readonly class LabSession
{
    public function __construct(private PrismHarness $harness) {}

    public function resolve(Request $request): Session
    {
        // Prism Lab has one local human operator and one overseer. The agent's
        // memory must survive browser-session rotation and remain the same in
        // the Benchmark Studio, flyout, and full chat surface.
        return $this->resolveScope('lab:agent');
    }

    public function resolveScope(string $scope): Session
    {
        if (trim($scope) === '' || strlen($scope) > 240) {
            throw new \InvalidArgumentException('A Lab Harness scope must contain 1–240 characters.');
        }

        return $this->harness->for($this->participant())->session($scope);
    }

    private function participant(): User
    {
        $identity = app(OwnerIdentity::class);
        if (Auth::guard('web')->check()) {
            return $identity->requireOwner();
        }
        // Native workers have no browser login. Their already approved work
        // identifies the owner without authenticating or creating an account.
        $intent = app(PaidRunContext::class)->authorizedRun(RunScope::ACTIONS);
        $owner = $identity->configuredOwner();
        if ($owner === null || $owner->id !== $intent->approved_by || $owner->id !== $intent->go_by) {
            throw new PaidRunNotAuthorised;
        }

        return $owner;
    }

    /** Prepare the native conversation identity without provisioning an account.
     * Empty thread creation is free preparation; message history/output is not
     * fingerprinted again during an allowed tool loop.
     */
    public function approvalSnapshot(): array
    {
        $owner = app(OwnerIdentity::class)->configuredOwner();
        if ($owner === null) {
            throw new PaidRunNotAuthorised;
        }
        $session = $this->harness->for($owner)->session('lab:agent');

        return ['participant_id' => (string) $owner->id, 'scope' => $session->scope(), 'key' => $session->key(),
            'thread_id' => (string) $session->thread()->getKey(),
            'capabilities_digest' => hash('sha256', CanonicalJson::encode([
                'browser' => $session->capability('browser'), 'human_plus' => $session->capability('human_plus'),
            ])), 'browser_attached' => $session->capability('browser') !== null,
            'human_plus_attached' => $session->capability('human_plus') !== null];
    }
}
