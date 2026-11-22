<?php

declare(strict_types=1);

namespace App\Acp\Accounts;

use App\Models\ClaudeAccount;
use Illuminate\Validation\ValidationException;

/**
 * Where added accounts live, and which one the Lab's Claude launches use.
 *
 * An account's directory is derived from its label under one Lab-owned folder; the stored path is checked against
 * that derivation before every use, so a row edited to point at the person's own `~/.claude`, or anywhere else, is
 * refused rather than signed in, out, or launched in.
 */
final class ClaudeAccountDirectory
{
    public const LABEL = '/\A[a-z0-9][a-z0-9-]{0,31}\z/';

    public function base(): string
    {
        return rtrim(str_replace('\\', '/', (string) config('acp.accounts.directory')), '/');
    }

    public function pathFor(string $label): string
    {
        if (! preg_match(self::LABEL, $label)) {
            throw new \InvalidArgumentException('invalid account label');
        }

        return $this->base().'/'.$label;
    }

    /** The account's directory, once its stored path is shown to be the one its label derives. */
    public function pathOf(ClaudeAccount $account): string
    {
        $expected = $this->pathFor($account->label);
        if (str_replace('\\', '/', $account->path) !== $expected) {
            throw new ClaudeAccountFailure('configDirInvalid');
        }

        return $expected;
    }

    /** Make the Lab-owned parent folder, private to this user. prism-acp requires the parent to exist. */
    public function ensureBase(): void
    {
        $base = $this->base();
        if (! is_dir($base) && ! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new ClaudeAccountFailure('configDirInvalid');
        }
    }

    public function active(): ?ClaudeAccount
    {
        return ClaudeAccount::query()->where('active', true)->orderBy('id')->first();
    }

    /**
     * What an approved Claude launch will use, frozen into its approval: the label and directory, or nulls for the
     * CLI's default account.
     *
     * @return array{label: string|null, config_dir: string|null}
     */
    public function activeSelection(): array
    {
        $account = $this->active();
        if ($account === null) {
            return ['label' => null, 'config_dir' => null];
        }
        try {
            return ['label' => $account->label, 'config_dir' => $this->pathOf($account)];
        } catch (ClaudeAccountFailure) {
            throw ValidationException::withMessages(['claude_account' => 'The active Claude account has an unusable directory. Choose another at /lab/accounts.']);
        }
    }

    /**
     * The environment a Claude launch is built from: `CLAUDE_CONFIG_DIR` set to the chosen account's directory, or
     * removed for the default account -- including one the Lab itself inherited, so the default here is the same
     * default /lab/accounts describes.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function launchEnvironment(array $env, ?string $configDir): array
    {
        foreach (array_keys($env) as $name) {
            if (strtoupper((string) $name) === 'CLAUDE_CONFIG_DIR') {
                unset($env[$name]);
            }
        }
        if ($configDir !== null) {
            $env['CLAUDE_CONFIG_DIR'] = $configDir;
        }

        return $env;
    }
}
