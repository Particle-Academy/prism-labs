<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Consensus\ConsensusCoordinator;
use App\Http\Controllers\Controller;
use App\Learnings\Learning;
use App\Models\ConsensusResponse;
use App\Models\ConsensusRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class ConsensusController extends Controller
{
    public function show(): Response
    {
        $runs = ConsensusRun::query()->with('responses')->latest()->limit(20)->get();

        // One query for every learning on the page rather than one per run.
        // The page renders up to twenty runs and the list is polled by a human
        // clicking around, so N+1 here is twenty round trips for a panel that
        // is usually showing one.
        $learnings = Learning::query()
            ->whereIn('ref', $runs->pluck('learning_ref')->filter()->unique()->values())
            ->get()
            ->keyBy('ref');

        return Inertia::render('Lab/Consensus', [
            'runs' => $runs->map(fn (ConsensusRun $run): array => [
                'id' => $run->id,
                'question' => $run->question,
                'status' => $run->status,
                'evidence_digest' => $run->evidence_digest,
                'synthesis' => $run->synthesis,
                'reviewed_at' => $run->reviewed_at?->toIso8601String(),
                'abandoned_at' => $run->abandoned_at?->toIso8601String(),
                'abandon_reason' => $run->abandon_reason,
                'created_at' => $run->created_at?->toIso8601String(),
                'responses' => $run->responses->map(fn (ConsensusResponse $response): array => [
                    'id' => $response->id,
                    'agent' => $response->agent,
                    'language' => $response->language,
                    'status' => $response->status,
                    'answer' => $response->answer,
                    'evidence' => $response->evidence,
                    // A string from the decimal cast, and kept as one. Casting
                    // it to a float here would turn "no confidence stated"
                    // into 0.0 on the way to the page, which reads as an agent
                    // that is certain it is wrong.
                    'confidence' => $response->confidence,
                    'dissent' => $response->dissent,
                ])->values()->all(),
                'tally' => $this->tally($run->responses),
                'learning' => $this->learning($run, $learnings),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, ConsensusCoordinator $coordinator): RedirectResponse
    {
        $input = $request->validate([
            'question' => ['required', 'string', 'max:12000'],
            'evidence' => ['nullable', 'string', 'max:20000'],
        ]);

        $coordinator->collect($input['question'], ['brief' => $input['evidence'] ?? '']);

        return to_route('lab.consensus');
    }

    public function review(Request $request, ConsensusRun $run, ConsensusCoordinator $coordinator): RedirectResponse
    {
        $input = $request->validate(['synthesis' => ['required', 'string', 'max:20000']]);
        $coordinator->review($run, $input['synthesis']);

        return to_route('lab.consensus');
    }

    /**
     * Close a run nobody is going to synthesise.
     *
     * The reason is optional because requiring one is how a surface teaches
     * people to type "n/a" — but it is asked for, because "we asked the wrong
     * question" and "both agents were down" are different findings and the 0L
     * repeats whichever it is given.
     */
    public function abandon(Request $request, ConsensusRun $run, ConsensusCoordinator $coordinator): RedirectResponse
    {
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $coordinator->abandon($run, $input['reason'] ?? '');

        return to_route('lab.consensus');
    }

    /**
     * What the roster actually did, counted.
     *
     * There is deliberately no "agreement" number here. Nothing on this
     * surface compares two natural-language answers for meaning, so the only
     * disagreement it can honestly report is the kind an agent DECLARED by
     * filling its dissent field.
     *
     * An agreement percentage would be an unaccountable figure: no rubric, no
     * weighted dimensions, no cited receipt behind it — a machine opinion
     * wearing a measurement's clothes. The human synthesis is where a verdict
     * belongs, and it is signed by whoever wrote it.
     *
     * @param  Collection<int, ConsensusResponse>  $responses
     * @return array{agents: int, responded: int, unavailable: int, dissenting: int}
     */
    private function tally(Collection $responses): array
    {
        $responded = $responses->where('status', 'responded');

        return [
            'agents' => $responses->count(),
            'responded' => $responded->count(),
            'unavailable' => $responses->count() - $responded->count(),
            'dissenting' => $responses
                ->filter(fn (ConsensusResponse $r): bool => is_string($r->dissent) && trim($r->dissent) !== '')
                ->count(),
        ];
    }

    /**
     * The 0Learning this run filed, if it has filed one.
     *
     * Every terminal consensus run files one — including the ones that
     * collected nothing, which are the most worth reading. It was written to
     * disk and to the database and then shown on no page belonging to the run
     * that produced it, which is the same defect the Run Room had.
     *
     * @param  Collection<string, Learning>  $learnings
     * @return array<string, mixed>|null
     */
    private function learning(ConsensusRun $run, Collection $learnings): ?array
    {
        if (! is_string($run->learning_ref) || $run->learning_ref === '') {
            return null;
        }

        $learning = $learnings->get($run->learning_ref);

        return $learning === null ? null : [
            'ref' => $learning->ref,
            'title' => $learning->title,
            'severity' => $learning->severity->value,
            'severity_label' => $learning->severity->label(),
            'what_was_learned' => $learning->what_was_learned,
            'evidence' => $learning->evidence,
            'why_it_matters' => $learning->why_it_matters,
            'what_should_change' => $learning->what_should_change,
            'path' => $learning->path,
        ];
    }
}
