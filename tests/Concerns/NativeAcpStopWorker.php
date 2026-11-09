<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Free test cleanup, restricted to the explicit disposable fixture database. */
final class NativeAcpStopWorker implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $directory = config('testing-native-acp.evidence_directory');
        $root = str_replace('\\', '/', dirname(base_path(), 3));
        $owned = false;
        if (is_string($directory) && str_starts_with($directory, str_replace('\\', '/', storage_path('framework/testing')).'/acp-native-')) {
            $owned = NativeAcpTurnFixture::directory($directory) === $directory;
        }
        foreach (['/owner6762/resume-owner6853/', '/owner7253-resume/'] as $continuation) {
            $prefix = $root.'/.ai/evidence/g246-foundations/approval-foundation'.$continuation;
            if (is_string($directory) && str_starts_with($directory, $prefix)
                && preg_match('~^acp-normal-host-v[0-9]+/(claude|codex)$~D', substr($directory, strlen($prefix))) === 1
                && str_replace('\\', '/', (string) realpath($directory)) === $directory && ! is_link($directory)) {
                $owned = true;
            }
        }
        if (! $owned || app('db')->connection()->getDriverName() !== 'sqlite'
            || app('db')->connection()->getDatabaseName() !== $directory.'/lab.sqlite') {
            throw new \RuntimeException('Refuse to stop a non-fixture worker.');
        }
        app('queue.worker')->shouldQuit = true;
    }
}
