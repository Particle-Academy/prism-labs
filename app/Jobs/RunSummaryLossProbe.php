<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benchmarks\SummaryLossProbe;
use App\Models\CompactionProbeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The summary-loss probe, off the request thread.
 *
 * Longer than {@see RunCompactionRecallProbe} rather than merely as long: this
 * one summarises with a second model on every compacting turn, so each arm pays
 * a model call per turn on top of the conversation itself. It was never going
 * to fit in a request and is not tried there.
 *
 * Queued on `default`, which the Lab's existing workflow worker already serves.
 */
final class RunSummaryLossProbe implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry would spend another two dozen live calls to answer a
     * question the first attempt already answered, and would hide a real
     * failure behind an eventual pass.
     */
    public int $tries = 1;

    /**
     * Strictly under the worker's own 1800s, so the JOB times out first.
     *
     * At parity the two race, and if the worker wins it kills the process
     * before `failed()` can run — which loses the one row that says the probe
     * was asked and did not answer. That is the outcome this class most needs
     * to record, so it must not be the one the timeout eats.
     */
    public int $timeout = 1700;

    public function handle(SummaryLossProbe $probe): void
    {
        $result = $probe->run();
        $with = $result['with_recall'];
        $without = $result['without_recall'];

        CompactionProbeRun::query()->create([
            'probe' => 'summary-loss',
            'verdict' => $result['verdict'],
            'reserved' => false,
            'model' => $result['summariser'],
            'looked' => $with['looked'],
            'correct' => $with['correct'],
            // The guard, stored under the column that already means it: the
            // detail provably left every window the model was given.
            'fact_left_window' => $with['summary_dropped_it'],
            // Read off the CONTROL, not the recall arm. The question is what an
            // agent does when the nuance is gone and it has no way to look it
            // up, and the recall arm is by construction not that agent.
            'confabulated' => $without['confabulated'],
            'answer' => mb_substr((string) $with['answer'], 0, 500),
            'failure' => $with['failure'] ?? $without['failure'],
        ]);
    }

    /**
     * A job that died still owes the page a row — otherwise the worst outcome
     * is the one that leaves no trace and the page shows an older run as
     * though it were current.
     */
    public function failed(?\Throwable $failure): void
    {
        CompactionProbeRun::query()->create([
            'probe' => 'summary-loss',
            'verdict' => 'error',
            'reserved' => false,
            'model' => 'claude-haiku-4-5-20251001',
            'failure' => $failure?->getMessage() ?? 'the job failed without an exception',
        ]);
    }
}
