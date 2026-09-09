<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Jobs\RunCompactionRecallProbe;
use App\Jobs\RunCompactionReservationProbe;
use App\Jobs\RunSummaryLossProbe;
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
 * All three probes drive a real multi-round agent loop against a live provider,
 * deliberately, because compaction only happens on a real transcript. That is
 * why they are buttons rather than something on page load: each press spends
 * money.
 *
 * ALL THREE ARE QUEUED, and the one that was not is why this paragraph is
 * rewritten. This comment used to say "the reservation probe fits in a request"
 * — it does not. Fourteen rounds of a live agent loop is minutes, and this app
 * is served SINGLE-THREADED: measured on the running site, one request takes
 * 0.6s and four concurrent take 2.6s, so they serialise.
 *
 * An inline probe therefore did not merely make its own button slow. It froze
 * the entire Lab for as long as it ran, and that surfaced as four unrelated-
 * looking bugs at once: a progress bar that vanished, a button stuck on
 * "Running…", no result appearing, and a chat panel that would not open. One
 * blocked thread.
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

    /**
     * The reservation probe. QUEUED, like the other two.
     *
     * It ran inline here, and the comment above this class said it "fits in a
     * request". It does not: fourteen rounds of a live agent loop is minutes,
     * and this app is served single-threaded — measured, one request 0.6s and
     * four concurrent 2.6s. So an inline run did not just block its own button,
     * it froze the entire Lab until it finished, which is what a stuck
     * "Running…" and an unopenable chat panel actually were.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reserved' => ['nullable', 'boolean'],
            'trigger' => ['nullable', 'integer', 'min:1000', 'max:200000'],
            'keep' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $reserved = (bool) ($validated['reserved'] ?? true);

        RunCompactionReservationProbe::dispatch(
            reserved: $reserved,
            trigger: (int) ($validated['trigger'] ?? 5000),
            keep: (int) ($validated['keep'] ?? 3),
        );

        return back()->with(
            'status',
            $reserved
                ? 'Reservation probe queued. It drives a long agent loop against a live provider — give it a couple of minutes and reload.'
                : 'Control (unreserved) queued. Give it a couple of minutes and reload.',
        );
    }
}
