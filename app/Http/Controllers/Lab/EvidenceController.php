<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Learnings\Learning;
use App\Learnings\LearningBacklog;
use App\Team\Nudge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The learnings registry.
 *
 * This page used to be three static cards describing what a 0Learning is,
 * including one headed "0Learning — attached per lane", on a surface that had
 * never displayed one. It now shows the actual backlog.
 */
final class EvidenceController extends Controller
{
    public function show(LearningBacklog $backlog): Response
    {
        return Inertia::render('Lab/Evidence', [
            'open' => $backlog->open()->map(fn (Learning $l): array => $this->present($l))->all(),
            'closed' => Learning::query()->whereNotNull('acted_at')->latest('acted_at')->limit(25)
                ->get()->map(fn (Learning $l): array => $this->present($l))->all(),
        ]);
    }

    /**
     * Hand the open backlog to an agent.
     *
     * HUMAN-TRIGGERED: nothing sends on a schedule or when a run finishes. The
     * operator decides a batch is worth an agent's attention and presses send;
     * delivery from there is automatic.
     */
    public function send(LearningBacklog $backlog, Nudge $nudge): RedirectResponse
    {
        $open = $backlog->open();

        if ($open->isEmpty()) {
            return to_route('lab.evidence')->with('status', 'Nothing open to send.');
        }

        // Delivered by the same `Nudge` the team board already uses. Everything
        // hard-won in that path — resolving the agent per send, and reading
        // BOTH of Genie's failure channels rather than only `isError` — applies
        // here for free. I had written a second bridge before finding it; the
        // duplicate is deleted.
        $result = $nudge->sendBacklog($open);

        if (($result['ok'] ?? false) !== true) {
            return to_route('lab.evidence')->with('error', 'Could not reach an agent: '.($result['reason'] ?? 'unknown reason'));
        }

        // Marked SENT, not closed. Being handed to an agent is not the same as
        // being dealt with, and collapsing the two would quietly lose every
        // learning that was passed on and then dropped.
        $backlog->markSent($open);

        return to_route('lab.evidence')->with('status', sprintf('%d learning(s) sent to the agent in this workspace.', $open->count()));
    }

    public function close(Request $request, LearningBacklog $backlog): RedirectResponse
    {
        $input = $request->validate([
            'ref' => ['required', 'string', 'max:32'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $backlog->close($input['ref'], $input['note']) === null
            ? to_route('lab.evidence')->with('error', sprintf('No learning found with ref [%s].', $input['ref']))
            : to_route('lab.evidence')->with('status', $input['ref'].' closed.');
    }

    /** @return array<string, mixed> */
    private function present(Learning $learning): array
    {
        return [
            'ref' => $learning->ref,
            'title' => $learning->title,
            'severity' => $learning->severity->value,
            'severity_label' => $learning->severity->label(),
            'filed_by' => $learning->filed_by,
            'what_was_learned' => $learning->what_was_learned,
            'evidence' => $learning->evidence,
            'why_it_matters' => $learning->why_it_matters,
            'what_should_change' => $learning->what_should_change,
            'path' => $learning->path,
            'sent_at' => $learning->sent_at?->toDateTimeString(),
            'acted_at' => $learning->acted_at?->toDateTimeString(),
            'acted_note' => $learning->acted_note,
        ];
    }
}
