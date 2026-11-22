<?php

declare(strict_types=1);

namespace App\Acp\Accounts;

use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Reaches prism-acp's TypeScript `claudeAccounts` API through `scripts/claude-accounts.mjs`, run with the node on PATH.
 *
 * The helper gets a minimal environment: the names a CLI needs to find itself, its home and its temporary folder, and
 * nothing of the Lab's own (no APP_KEY, no provider key). prism-acp strips the CLI's environment again on top of that.
 * Its stdout is one JSON event per line; its stderr is never read into a log or a response.
 */
final class NodeClaudeAccountBridge implements ClaudeAccountBridge
{
    /** Mirrors prism-acp's CLAUDE_SIGN_IN_HOSTS. prism-acp is the authority; this is a second, fail-closed check. */
    private const SIGN_IN_HOSTS = ['claude.ai', 'claude.com', 'platform.claude.com', 'console.anthropic.com'];

    private const ENVIRONMENT = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'SYSTEMDRIVE', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'TMPDIR', 'HOME',
        'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA', 'PROGRAMFILES',
        'PROGRAMFILES(X86)', 'PROGRAMW6432', 'USERNAME', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'LC_ALL',
        'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR',
    ];

    public function available(): bool
    {
        $module = (string) config('acp.accounts.module');

        return $module !== '' && is_file($module);
    }

    public function describe(?string $configDir): array
    {
        return self::view($this->run('describe', ['configDir' => $configDir, ...$this->cli()], 60));
    }

    public function logout(string $configDir): array
    {
        return self::view($this->run('logout', ['configDir' => $configDir, ...$this->cli()], 90));
    }

    public function prepare(string $configDir, bool $sharedSessions): array
    {
        $value = $this->run('prepare', ['configDir' => $configDir, 'sharedSessions' => $sharedSessions], 30);

        return ['sharedSessions' => ($value['sharedSessions'] ?? false) === true, 'linked' => is_string($value['linked'] ?? null) ? $value['linked'] : null];
    }

    public function shared(string $configDir): ?bool
    {
        $shared = $this->run('shared', ['configDir' => $configDir], 30)['shared'] ?? null;

        return is_bool($shared) ? $shared : null;
    }

    public function login(string $configDir, string $method, Closure $onUrl, Closure $cancelled, int $seconds): array
    {
        $input = new InputStream;
        $process = $this->process('login', ['configDir' => $configDir, 'method' => $method, 'timeoutMs' => $seconds * 1000, ...$this->cli()], $seconds + 60);
        $process->setInput($input);
        $outcome = null;
        $buffer = '';
        $cancelSent = false;
        $drain = function (string $chunk) use (&$buffer, &$outcome, $onUrl): void {
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                $event = json_decode(trim(substr($buffer, 0, $end)), true);
                $buffer = substr($buffer, $end + 1);
                if (! is_array($event)) {
                    continue;
                }
                if (($event['type'] ?? null) === 'url' && $outcome === null) {
                    $url = self::signInUrl($event['url'] ?? null);
                    if ($url === null) {
                        $outcome = ['type' => 'error', 'code' => 'refusedUrl'];

                        continue;
                    }
                    $onUrl($url);
                } elseif (in_array($event['type'] ?? null, ['result', 'error'], true)) {
                    $outcome ??= $event;
                }
            }
        };
        try {
            $process->start();
            while (true) {
                $drain($process->getIncrementalOutput());
                if (! $process->isRunning()) {
                    $drain($process->getIncrementalOutput()."\n");
                    break;
                }
                // A URL this side refused ends the sign-in as surely as Cancel does.
                if (! $cancelSent && ($cancelled() || ($outcome['code'] ?? null) === 'refusedUrl')) {
                    $input->write("cancel\n");
                    $cancelSent = true;
                }
                $process->checkTimeout();
                usleep(200_000);
            }
        } catch (ProcessTimedOutException) {
            $outcome = ['type' => 'error', 'code' => 'timeout'];
        } finally {
            $input->close();
            if ($process->isRunning()) {
                $process->stop(10);
            }
        }

        return self::view(self::value($outcome));
    }

    /** @return array{binary: string, binaryArgs: list<string>} */
    private function cli(): array
    {
        return ['binary' => (string) config('acp.claude.binary'), 'binaryArgs' => array_values(array_map('strval', (array) config('acp.claude.binary_args')))];
    }

    private function process(string $command, array $args, int $seconds): Process
    {
        if (! $this->available()) {
            throw new ClaudeAccountFailure('moduleUnavailable');
        }

        return new Process([(string) config('acp.accounts.node'), base_path('scripts/claude-accounts.mjs'), $command, json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            base_path(), $this->environment(), null, $seconds);
    }

    private function run(string $command, array $args, int $seconds): array
    {
        $process = $this->process($command, $args, $seconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ClaudeAccountFailure('timeout');
        }
        $outcome = null;
        foreach (preg_split('/\r?\n/', $process->getOutput()) ?: [] as $line) {
            $event = json_decode(trim($line), true);
            if (is_array($event) && in_array($event['type'] ?? null, ['result', 'error'], true)) {
                $outcome ??= $event;
            }
        }

        return self::value($outcome);
    }

    private static function value(?array $outcome): array
    {
        if (($outcome['type'] ?? null) === 'result' && is_array($outcome['value'] ?? null)) {
            return $outcome['value'];
        }
        $code = $outcome['code'] ?? null;
        throw new ClaudeAccountFailure(is_string($code) && ClaudeAccountFailure::known($code) ? $code : 'bridgeFailed');
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        $inherited = getenv();
        $inherited = is_array($inherited) ? $inherited : [];
        $env = [];
        // Symfony's Process starts from getenv() plus $_ENV and the string entries of $_SERVER (where Laravel's dotenv
        // puts .env values), so every one of those names is removed unless it is on the list.
        foreach ([...array_keys($_SERVER), ...array_keys($_ENV), ...array_keys($inherited)] as $name) {
            $env[(string) $name] = false;
        }
        foreach ($inherited as $name => $value) {
            if (in_array(strtoupper((string) $name), self::ENVIRONMENT, true) && is_string($value)) {
                $env[$name] = $value;
            }
        }
        $env['LAB_PRISM_ACP_MODULE'] = (string) config('acp.accounts.module');

        return $env;
    }

    /** The named fields a status may carry to the page. */
    public static function view(array $status): array
    {
        $text = fn (mixed $value): ?string => is_string($value) && strlen($value) <= 320 ? $value : null;

        return [
            'signedIn' => ($status['signedIn'] ?? false) === true,
            'method' => in_array($status['method'] ?? null, ['subscription', 'console', 'apiKey', 'none', 'other'], true) ? $status['method'] : 'other',
            'billing' => in_array($status['billing'] ?? null, ['subscription', 'apiUsage', 'none', 'unknown'], true) ? $status['billing'] : 'unknown',
            'email' => $text($status['email'] ?? null),
            'organizationName' => $text($status['organizationName'] ?? null),
            'subscriptionType' => $text($status['subscriptionType'] ?? null),
            'configDirectoryMatches' => ($status['configDirectoryMatches'] ?? false) === true,
            'loginExpiresAt' => $text($status['loginExpiresAt'] ?? null),
        ];
    }

    /** `https://` on an exact sign-in host, no user info, no port, no whitespace or control characters, bounded. */
    public static function signInUrl(mixed $candidate): ?string
    {
        if (! is_string($candidate) || strlen($candidate) > 8192 || preg_match('/[\x00-\x20\x7f]/', $candidate)) {
            return null;
        }
        $parts = parse_url($candidate);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array($parts['host'] ?? '', self::SIGN_IN_HOSTS, true)) {
            return null;
        }

        return $candidate;
    }
}
