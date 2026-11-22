<?php

declare(strict_types=1);

namespace App\Acp\Accounts;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A sign-in in progress, between the worker running it and the page polling it. Transient: it expires shortly after
 * the sign-in's own deadline, and the URL is dropped the moment the sign-in ends. It never holds a credential -- the
 * sign-in URL only starts Anthropic's own flow, and the CLI keeps what that flow produces.
 *
 * States: queued (waiting for a worker), waiting (the URL is out, the person is signing in), signedIn, signedOut (the
 * CLI finished but reports no sign-in), failed, cancelled.
 */
final class LoginState
{
    public const PENDING = ['queued', 'waiting'];

    private function store(): Repository
    {
        return Cache::store((string) config('acp.accounts.login_store'));
    }

    private function ttl(): int
    {
        return max(60, (int) config('acp.accounts.login_seconds')) + 600;
    }

    private static function key(int $account): string
    {
        return 'lab:claude-login:'.$account;
    }

    /** @return array{state: string, token: string, url?: string|null, code?: string|null, message?: string|null, status?: array|null}|null */
    public function get(int $account): ?array
    {
        $state = $this->store()->get(self::key($account));

        return is_array($state) ? $state : null;
    }

    public function pending(int $account): bool
    {
        return in_array($this->get($account)['state'] ?? null, self::PENDING, true);
    }

    public function begin(int $account): string
    {
        $token = (string) Str::uuid();
        $this->store()->put(self::key($account), ['state' => 'queued', 'token' => $token], $this->ttl());

        return $token;
    }

    /** Update the state, but only for the sign-in that owns it: a newer one is never overwritten by an older one. */
    public function update(int $account, string $token, array $changes): void
    {
        $current = $this->get($account);
        if (($current['token'] ?? null) !== $token) {
            return;
        }
        $this->store()->put(self::key($account), [...$current, ...$changes, 'token' => $token], $this->ttl());
    }

    public function finish(int $account, string $token, string $state, ?array $status = null, ?string $code = null): void
    {
        $this->update($account, $token, ['state' => $state, 'url' => null, 'status' => $status, 'code' => $code,
            'message' => $code === null ? null : (new ClaudeAccountFailure($code))->getMessage()]);
    }

    public function requestCancel(int $account): void
    {
        $current = $this->get($account);
        if ($current === null || ! in_array($current['state'], self::PENDING, true)) {
            return;
        }
        $this->store()->put(self::key($account).':cancel:'.$current['token'], true, $this->ttl());
        if ($current['state'] === 'queued') {
            $this->finish($account, $current['token'], 'cancelled', null, 'aborted');
        }
    }

    public function cancelRequested(int $account, string $token): bool
    {
        return $this->store()->get(self::key($account).':cancel:'.$token) === true;
    }

    /** What the page may see: never the token. */
    public function view(int $account): ?array
    {
        $state = $this->get($account);
        if ($state === null) {
            return null;
        }
        unset($state['token']);

        return $state;
    }
}
