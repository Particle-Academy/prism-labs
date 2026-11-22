<?php

declare(strict_types=1);

namespace App\Acp\Accounts;

use Closure;

/**
 * prism-acp's `claudeAccounts` API, as the Lab reaches it. Every status is the named-field view
 * {@see NodeClaudeAccountBridge::view()} returns, and every failure is a {@see ClaudeAccountFailure} carrying a code.
 */
interface ClaudeAccountBridge
{
    /** Whether the TypeScript package can be reached at all. */
    public function available(): bool;

    /** `claudeAccounts.describe(configDir)`; null is the CLI's default account. */
    public function describe(?string $configDir): array;

    /**
     * `claudeAccounts.login(configDir, …)`. Blocks until the sign-in ends. `$onUrl` receives the checked sign-in URL
     * once; `$cancelled` is polled, and a true answer aborts the sign-in (prism-acp then kills the CLI's tree).
     *
     * @param  Closure(string): void  $onUrl
     * @param  Closure(): bool  $cancelled
     */
    public function login(string $configDir, string $method, Closure $onUrl, Closure $cancelled, int $seconds): array;

    /** `claudeAccounts.logout(configDir)`. */
    public function logout(string $configDir): array;

    /** `claudeAccounts.prepare(configDir, { sharedSessions })`. */
    public function prepare(string $configDir, bool $sharedSessions): array;

    /** Whether `<configDir>/projects` is a link to the shared store: true, false (its own), or null (not there yet). */
    public function shared(string $configDir): ?bool;
}
