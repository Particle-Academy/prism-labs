<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Acp\Accounts\ClaudeAccountBridge;
use App\Acp\Accounts\ClaudeAccountDirectory;
use App\Acp\Accounts\ClaudeAccountFailure;
use App\Acp\Accounts\LoginState;
use App\Models\ClaudeAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sign one added Claude account in, through prism-acp's `claudeAccounts.login`, off the web worker.
 *
 * A sign-in waits on the person for up to `acp.accounts.login_seconds`, so it cannot run in a request. On the overseer
 * queue for the reason RunAcpTurnJob is: a person is watching. The URL goes to {@see LoginState}, which the page polls
 * and opens in the owner's browser; nothing about the sign-in's result is kept except whether it signed in.
 */
final class ClaudeAccountLoginJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: a retry would start a second sign-in the person did not ask for. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $accountId, public readonly string $method, public readonly string $token)
    {
        $this->onQueue('overseer');
    }

    public function handle(ClaudeAccountBridge $bridge, LoginState $logins, ClaudeAccountDirectory $directories): void
    {
        $current = $logins->get($this->accountId);
        if (($current['token'] ?? null) !== $this->token || $current['state'] !== 'queued') {
            return;
        }
        if ($logins->cancelRequested($this->accountId, $this->token)) {
            $logins->finish($this->accountId, $this->token, 'cancelled', null, 'aborted');

            return;
        }
        $account = ClaudeAccount::query()->find($this->accountId);
        try {
            if (! $account instanceof ClaudeAccount) {
                throw new ClaudeAccountFailure('configDirInvalid');
            }
            $logins->update($this->accountId, $this->token, ['state' => 'waiting']);
            $status = $bridge->login(
                $directories->pathOf($account),
                $this->method === 'console' ? 'console' : 'subscription',
                fn (string $url) => $logins->update($this->accountId, $this->token, ['url' => $url]),
                fn (): bool => $logins->cancelRequested($this->accountId, $this->token),
                max(60, min(3600, (int) config('acp.accounts.login_seconds'))),
            );
            $logins->finish($this->accountId, $this->token, $status['signedIn'] ? 'signedIn' : 'signedOut', $status);
        } catch (ClaudeAccountFailure $failure) {
            $logins->finish($this->accountId, $this->token, $failure->reason === 'aborted' ? 'cancelled' : 'failed', null, $failure->reason);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LoginState::class)->finish($this->accountId, $this->token, 'failed', null, 'bridgeFailed');
    }
}
