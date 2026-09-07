<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benchmarks\CompactionRecallProbe;
use App\Models\CompactionProbeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The recall probe, off the request thread.
 *
 * IT DOES NOT FIT IN AN HTTP REQUEST, and that is a fact about the probe rather
 * than a tuning problem. It drives two arms of a real multi-turn conversation
 * against a live provider — a dozen round trips at several seconds each — and
 * run from a controller it kept working long after the browser had given up.
 * The visible symptom was the worst kind: evicted rows accumulating in the
 * table for minutes while no verdict was ever recorded, so the page looked
 * like nothing had happened.
 *
 * Shortening the conversation was tried first and was not enough, which is the
 * evidence that this is the wrong shape for a request rather than a request
 * that needs a bigger timeout.
 *
 * Queued on `default`, which is the queue the Lab's existing workflow worker
 * already serves at a 1800s timeout — long enough, and nothing new to run.
 */
final class RunCompactionRecallProbe implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry would spend another dozen live provider calls to
     * answer a question the first attempt already answered — and a probe that
     * silently retried would also hide a genuine failure behind an eventual
     * pass, which is the opposite of what it is for.
     */
    public int $tries = 1;

    public int $timeout = 1500;

    public function handle(CompactionRecallProbe $probe): void
    {
        $result = $probe->run();
        $with = $result['with_recall'];

        CompactionProbeRun::query()->create([
            'probe' => 'recall',
            'verdict' => $result['verdict'],
            'reserved' => false,
            'model' => 'claude-sonnet-5',
            'looked' => $with['looked'],
            'correct' => $with['correct'],
            'fact_left_window' => $with['secret_was_evicted'],
            'answer' => mb_substr((string) $with['answer'], 0, 500),
            'failure' => $with['failure'],
        ]);
    }

    /**
     * A job that died still owes the page a row.
     *
     * Without this the probe's worst outcome — the worker crashing, or the run
     * exceeding its timeout — is the one that leaves no trace, and the page
     * shows the last successful run as though it were current. An `error` row
     * is the honest record that it was asked and did not answer.
     */
    public function failed(?\Throwable $failure): void
    {
        CompactionProbeRun::query()->create([
            'probe' => 'recall',
            'verdict' => 'error',
            'reserved' => false,
            'model' => 'claude-sonnet-5',
            'failure' => $failure?->getMessage() ?? 'the job failed without an exception',
        ]);
    }
}
