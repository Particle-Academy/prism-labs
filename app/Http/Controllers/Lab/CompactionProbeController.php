<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Benchmarks\CompactionReservationProbe;
use App\Http\Controllers\Controller;
use App\Jobs\RunCompactionRecallProbe;
use App\Jobs\RunSummaryLossProbe;
use App\Models\CompactionProbeRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Run the compaction probe FROM THE LAB, and keep every result.
 *
 * This exists because the probe was first built as a headless endpoint driven
 * by a script outside the repository, which meant the one place the ecosystem
 * is supposed to be dogfooded — the Lab, in a browser — showed nothing at all.
 * A guarantee nobody can see the state of is not being watched.
 *
 * Both probes drive a real multi-round agent loop against a live provider,
 * deliberately, because compaction only happens on a real transcript. That is
 * why they are buttons rather than something on page load: each press spends
 * money.
 *
 * THEY DIFFER IN WHERE THEY RUN, and the difference is not a preference. The
 * reservation probe fits in a request. The recall probe drives TWO arms of a
 * full conversation and does not — run inline it kept working long after the
 * browser had given up, so it is queued. See {@see RunCompactionRecallProbe}.
 */
final class CompactionProbeController extends Controller
{
    /**
     * The recall probe: is an evicted turn still reachable?
     *
     * QUEUED, not run here. It drives two arms of a real multi-turn
     * conversation and outlives an HTTP request — run synchronously it kept
     * working long after the browser gave up, writing evicted rows for minutes
     * while no verdict was ever recorded. See {@see RunCompactionRecallProbe}.
     *
     * Separate action from the reservation probe because they answer different
     * questions and one failing says nothing about the other. Shared history,
     * because they are two halves of "what happens when the window shrinks".
     */
    public function recall(): RedirectResponse
    {
        RunCompactionRecallProbe::dispatch();

        return back()->with(
            'status',
            'Recall probe queued. It drives two full conversations against a live provider, '
            .'so give it a couple of minutes and reload — the result appears below.',
        );
    }

    /**
     * The summary-loss probe: what does a summariser drop, and is it reachable?
     *
     * A separate button from the recall probe because it is a separate
     * question. Recall plants an IDENTIFIER, which the summariser's prompt is
     * written to protect and duly kept on every run — so that probe reports
     * "summary carried the fact" and never reaches the recall branch under
     * summarisation. This one plants a REASON mentioned in passing, which
     * nothing in that prompt protects.
     *
     * Queued for the same reason, more so: both arms pay a summarising model
     * call on every compacting turn on top of the conversation itself.
     */
    public function summaryLoss(): RedirectResponse
    {
        RunSummaryLossProbe::dispatch();

        return back()->with(
            'status',
            'Summary-loss probe queued. Two full conversations, each summarised by a second '
            .'model on every compacting turn — give it a few minutes and reload.',
        );
    }

    public function store(Request $request, CompactionReservationProbe $probe): RedirectResponse
    {
        $validated = $request->validate([
            'reserved' => ['nullable', 'boolean'],
            'trigger' => ['nullable', 'integer', 'min:1000', 'max:200000'],
            'keep' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $reserved = (bool) ($validated['reserved'] ?? true);

        $result = $probe->run(
            trigger: (int) ($validated['trigger'] ?? 5000),
            keep: (int) ($validated['keep'] ?? 3),
            reserve: $reserved,
        );

        CompactionProbeRun::query()->create([
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

        // A BREACH is stated as a breach, not as "completed". The one outcome
        // this whole surface exists to catch must not read like a normal run.
        $message = match (true) {
            $result['verdict'] === 'BREACH' => 'BREACH — a reserved tool EXECUTED while the window was compacted.',
            $result['verdict'] === 'held' => sprintf(
                'Held — %d attempts refused across %d cleared tool uses.',
                $result['attempts'],
                $result['tool_uses_cleared'],
            ),
            default => 'Recorded: '.$result['verdict'],
        };

        return back()->with($result['verdict'] === 'BREACH' ? 'error' : 'status', $message);
    }
}
