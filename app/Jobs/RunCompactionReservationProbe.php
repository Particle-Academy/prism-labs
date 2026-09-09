<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benchmarks\CompactionReservationProbe;
use App\Models\CompactionProbeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The reservation probe, off the request thread — where it always belonged.
 *
 * IT USED TO RUN INLINE IN THE CONTROLLER, and the controller's own comment
 * said it "fits in a request". It does not. It drives up to fourteen rounds of
 * a real agent loop against a live provider with `clear_tool_uses` on, which is
 * minutes, and the Lab is served by a SINGLE-THREADED `php artisan serve`.
 *
 * Measured on the running site: one request takes 0.6s, four concurrent take
 * 2.6s — they serialise. So a multi-minute inline probe does not merely make
 * its own button slow, it FREEZES THE WHOLE LAB for as long as it runs. The
 * reported symptom was a progress bar that vanished, a button stuck on
 * "Running…", nothing appearing, and the chat panel refusing to open until the
 * probe finished — one blocked thread, four symptoms that look like four bugs.
 *
 * Queued on `default`, like the other two probes, which were moved for the same
 * reason and left this one behind.
 */
final class RunCompactionReservationProbe implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry would spend another fourteen rounds against a live
     * provider to answer a question the first attempt already answered, and
     * would hide a genuine failure behind an eventual pass.
     */
    public int $tries = 1;

    public int $timeout = 1700;

    public function __construct(
        private readonly bool $reserved = true,
        private readonly int $trigger = 5000,
        private readonly int $keep = 3,
    ) {}

    public function handle(CompactionReservationProbe $probe): void
    {
        $result = $probe->run(
            trigger: $this->trigger,
            keep: $this->keep,
            reserve: $this->reserved,
        );

        CompactionProbeRun::query()->create([
            'probe' => 'reservation',
            'verdict' => $result['verdict'],
            'reserved' => $result['reserved'],
            'model' => $result['model'],
            'steps' => $result['steps'],
            'ledger_reads' => $result['ledger_reads'],
            'attempts' => $result['attempts'],
            'executions' => $result['executions'],
            'tool_uses_cleared' => $result['tool_uses_cleared'],
            'denials' => $result['denials'],
            'duration_ms' => $result['duration_ms'],
            'failure' => $result['failure'],
        ]);
    }

    /**
     * A job that died still owes the page a row, or the worst outcome is the
     * one that leaves no trace and the page shows an older run as current.
     */
    public function failed(?\Throwable $failure): void
    {
        CompactionProbeRun::query()->create([
            'probe' => 'reservation',
            'verdict' => 'error',
            'reserved' => $this->reserved,
            'model' => 'claude-sonnet-5',
            'failure' => $failure?->getMessage() ?? 'the job failed without an exception',
        ]);
    }
}
