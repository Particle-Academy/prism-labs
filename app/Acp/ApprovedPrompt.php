<?php

declare(strict_types=1);

namespace App\Acp;

use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScope;
use App\Lab\RunScopeFactory;

/** One frozen prompt and one native session per already approved GO. */
final class ApprovedPrompt
{
    private bool $sessionClaimed = false;

    private bool $promptClaimed = false;

    private function __construct(
        private readonly string $id,
        private readonly RunScope $scope,
        private readonly RunAuthority $authority,
        private readonly RunScopeFactory $scopes,
        public readonly string $model,
        private readonly ?string $turnId,
    ) {}

    public static function acquire(string $id, string $provider, RunAuthority $authority, RunScopeFactory $scopes): self
    {
        $intent = $authority->approvedReference($id);
        $input = $intent->payload['input'] ?? null;
        if ($intent->action !== 'acp.send' || ! is_array($input)) {
            throw new PaidRunNotAuthorised;
        }
        $scope = $scopes->fromAction('acp.send', $intent->resource, $input);
        if (($scope->payload['acp']['provider'] ?? null) !== $provider) {
            throw new PaidRunNotAuthorised;
        }
        $turnId = AcpWork::assertHost($id);
        $authority->claimAcpHost($id, $scope);

        return new self($id, $scope, $authority, $scopes, $scope->payload['acp']['model'], $turnId);
    }

    /** @param array<string, mixed> $session */
    public function claimSession(array $session): void
    {
        if ($this->sessionClaimed || ($session['cwd'] ?? null) !== $this->scope->payload['acp']['workspace']
            || ($session['mcpServers'] ?? null) !== []
            || ($session['resumeSessionId'] ?? null) !== $this->scope->payload['acp']['resume_session_id']) {
            throw new PaidRunNotAuthorised;
        }
        $this->assertCurrent();
        $this->sessionClaimed = true;
    }

    public function claimPrompt(string|array $content): void
    {
        if (! $this->sessionClaimed || $this->promptClaimed || ! is_string($content)
            || ! hash_equals($this->scope->payload['input']['prompt'], $content)) {
            throw new PaidRunNotAuthorised;
        }
        $scope = $this->currentScope();
        $this->authority->claimAcpPrompt($this->id, $scope);
        $this->promptClaimed = true;
    }

    public function assertModel(string $model): void
    {
        if (! hash_equals($this->model, $model)) {
            throw new PaidRunNotAuthorised;
        }
        $this->assertCurrent();
    }

    private function assertCurrent(): void
    {
        $this->authority->assertActive($this->id, $this->currentScope());
    }

    private function currentScope(): RunScope
    {
        AcpWork::assertHost($this->id, $this->turnId);

        return $this->scopes->fromAction('acp.send', $this->scope->resource, $this->scope->payload['input']);
    }
}
