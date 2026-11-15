<?php

declare(strict_types=1);

namespace App\Drift;

use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * When the watcher's committed baseline (state/) was last committed.
 *
 * Read with an argument array, never a shell string. The checkout must be its
 * OWN repository: run inside a directory that only sits within some other
 * repository, `git log -- state/` would answer with the outer repository's
 * history and report a date that has nothing to do with the watcher.
 */
class WatcherGit
{
    public function lastStateCommit(string $path): ?CarbonImmutable
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            return null;
        }

        $toplevel = $this->git($real, ['rev-parse', '--show-toplevel']);
        if ($toplevel === null || self::normalise($toplevel) !== self::normalise($real)) {
            return null;
        }

        $date = $this->git($real, ['log', '-1', '--format=%cI', '--', 'state/']);
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $arguments */
    private function git(string $cwd, array $arguments): ?string
    {
        try {
            $process = new Process(['git', ...$arguments], $cwd, null, null, 5);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    private static function normalise(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
