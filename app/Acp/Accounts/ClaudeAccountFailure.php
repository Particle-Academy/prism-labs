<?php

declare(strict_types=1);

namespace App\Acp\Accounts;

/** A claudeAccounts call that did not finish. Only the code crosses: never the CLI's or the library's own text. */
final class ClaudeAccountFailure extends \RuntimeException
{
    private const SENTENCES = [
        'aborted' => 'The sign-in was cancelled. Nothing was left running.',
        'timeout' => 'The sign-in was not finished in time. Nothing was left running; add it again to retry.',
        'refusedUrl' => 'The CLI offered a sign-in address that is not an Anthropic or Claude page, so it was not opened.',
        'noUrl' => 'The CLI ended before offering a sign-in address.',
        'codeRequired' => 'This sign-in asked for a pasted code, which this page does not take yet. Nothing was left running.',
        'invalidCode' => 'The sign-in code was not accepted.',
        'failed' => 'The Claude CLI reported a failure.',
        'refusedDirectory' => 'The default account is signed in and out with the Claude CLI itself, not from here.',
        'missingDirectory' => 'This account has no directory yet, so there is nothing to sign out.',
        'realFolder' => 'This account already has its own thread history, so it was not replaced with the shared one.',
        'linkedElsewhere' => 'This account already shares threads with another store.',
        'occupied' => 'Something already occupies this account\'s projects folder.',
        'unexpectedLink' => 'This account shares threads already. Turning that off would move its history, which is a decision to make by hand.',
        'storeMissing' => 'The default account has no thread history to share yet.',
        'storeInvalid' => 'The shared thread store is not a folder.',
        'configDirInvalid' => 'This account\'s directory is not usable.',
        'linkFailed' => 'The operating system refused the link.',
        'moduleUnavailable' => 'The prism-acp TypeScript package is not built where the Lab looks for it.',
        'unsupported' => 'The prism-acp build the Lab found has no terminal-free sign-in.',
        'badRequest' => 'The account helper refused the request.',
        'bridgeFailed' => 'The account helper did not answer.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::SENTENCES[$reason] ?? self::SENTENCES['bridgeFailed']);
    }

    public static function known(string $code): bool
    {
        return isset(self::SENTENCES[$code]);
    }
}
