<?php

declare(strict_types=1);

namespace App\Consensus;

use App\Learnings\LearningStore;
use App\Learnings\Severity;
use App\Models\ConsensusResponse;
use App\Models\ConsensusRun;
use Illuminate\Support\Collection;
use Throwable;

/**
 * EVERY terminal consensus run leaves a 0L behind, including — especially —
 * the ones that produced nothing.
 *
 * Consensus filed NOTHING before this class existed, which made it the larger
 * of the two gaps on this side of the Lab. A consensus run spends one real
 * call to every addressable language agent and then freezes what came back; a
 * run whose agents disagreed, or whose ts lane was down, or that a human
 * opened once and never synthesised, ended with that spend recorded in two
 * database tables nobody greps and in no file anybody reads.
 *
 * Three terminal shapes, one recorder — the same reason the benchmark side has
 * one: "file a learning" is the step each path forgets in its own way.
 *
 * 1. Collection answered NOTHING. Filed the moment collection ends, without
 *    waiting for a human, because there is no synthesis coming that could add
 *    to it and the run would otherwise sit unreviewed forever.
 * 2. A human reviewed it. The synthesis is the most valuable text the surface
 *    produces and it belonged in the knowledge base, not only in a text area.
 * 3. A human abandoned it unreviewed. The case the directive names outright,
 *    and the one that used to be entirely invisible.
 *
 * What this class will NOT do is claim the agents agreed. Nothing on this
 * surface compares two natural-language answers for meaning, so the only
 * disagreement it reports is the kind an agent DECLARED by filling its
 * `dissent` field. Inferring consensus from two answers that merely look
 * similar would put a machine-invented verdict into the knowledge base under a
 * human-sounding sentence — and a 0L is read later by people who were not
 * here, which is exactly when an unearned claim does its damage.
 */
final readonly class ConsensusLearningRecorder
{
    public function __construct(private LearningStore $learnings) {}

    /**
     * Never throws. The run's status is the record of what happened; a 0L that
     * could not be written must not take that down with it. A missing learning
     * is a gap, a lost run status is corruption.
     */
    public function record(ConsensusRun $run): void
    {
        if (is_string($run->learning_ref) && $run->learning_ref !== '') {
            // Reachable from collection, review and abandonment, and a run can
            // pass through two of those. One run, one 0L.
            return;
        }

        try {
            $responses = ConsensusResponse::query()
                ->where('consensus_run_id', $run->id)
                ->orderBy('agent')
                ->get();

            $learning = $this->learnings->file(
                title: $this->title($run, $responses),
                filedBy: 'prism-lab/consensus',
                languages: $responses->pluck('language')->unique()->values()->all(),
                whatWasLearned: $this->whatWasLearned($run, $responses),
                evidence: $this->evidence($run, $responses),
                whyItMatters: $this->whyItMatters($run, $responses),
                whatShouldChange: $this->whatShouldChange($run, $responses),
                severity: $this->severity($run, $responses),
            );

            $run->forceFill(['learning_ref' => $learning->ref])->save();
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * The question, short enough to be a filename.
     *
     * `LearningStore` slugs the title into the path and the question field
     * accepts twelve thousand characters, so an untruncated title is a
     * filesystem error waiting for the first person who pastes a brief in.
     */
    private function question(ConsensusRun $run): string
    {
        $question = trim(preg_replace('/\s+/', ' ', (string) $run->question) ?? '');

        return $question === ''
            ? 'an unstated question'
            : (mb_strlen($question) > 90 ? mb_substr($question, 0, 89).'…' : $question);
    }

    /** @param Collection<int, ConsensusResponse> $responses */
    private function title(ConsensusRun $run, Collection $responses): string
    {
        $answered = $responses->where('status', 'responded')->count();
        $dissenting = $this->dissenting($responses)->count();

        return match (true) {
            $answered === 0 => sprintf('Consensus on "%s" — no agent answered', $this->question($run)),
            $run->status === 'abandoned' => sprintf('Consensus on "%s" — abandoned unreviewed with %d of %d answering', $this->question($run), $answered, $responses->count()),
            $dissenting > 0 => sprintf('Consensus on "%s" — %d of %d agents declared dissent', $this->question($run), $dissenting, $answered),
            default => sprintf('Consensus on "%s" — reviewed with %d of %d agents answering', $this->question($run), $answered, $responses->count()),
        };
    }

    /** @param Collection<int, ConsensusResponse> $responses */
    private function whatWasLearned(ConsensusRun $run, Collection $responses): string
    {
        $answered = $responses->where('status', 'responded');
        $dissenting = $this->dissenting($responses);

        $silent = $responses->where('status', '!=', 'responded');

        $headline = match (true) {
            $responses->isEmpty() => 'The roster offered no addressable agent, so this question was never actually put to anybody. The run is a record of the roster, not of the question.',
            $answered->isEmpty() => 'Not one agent answered, so this run holds no opinion on the question it asked. What it does hold is which lanes were unreachable at this moment, which is the part that is expensive to reproduce later.',
            $run->status === 'abandoned' => 'Opinions were collected and no human ever synthesised them. The answers below are therefore raw and unweighed — they are what the agents said, not what the coordinator concluded, and must not be cited as a position.'
                .($dissenting->isNotEmpty() ? sprintf(' %s declared a dissent that no synthesis ever weighed.', $dissenting->pluck('agent')->implode(' and ')) : ''),
            $dissenting->isNotEmpty() => sprintf('%d of the %d agents that answered recorded an EXPLICIT dissent alongside their answer. A synthesis exists, and it was written over declared disagreement rather than over agreement.', $dissenting->count(), $answered->count()),

            // Not the same sentence as unanimity, and it used to be. A run
            // where one lane was down and the other did not object was
            // described as "every agent that answered did so without recording
            // a dissent" — true, and read by anyone skimming as agreement
            // across the roster. An absent lane is not an agreeing lane, and
            // the headline is where that has to be said, because the headline
            // is the part that gets quoted.
            $silent->isNotEmpty() => sprintf(
                'Only %d of the %d agents on the roster answered, and none of those recorded a dissent. That is NOT unanimity: %s contributed nothing at all, so this run says nothing whatever about %s.',
                $answered->count(), $responses->count(),
                $silent->pluck('agent')->implode(' and '),
                $silent->pluck('language')->unique()->implode(' or '),
            ),
            default => 'Every agent on the roster answered and none recorded a dissent, and a human synthesised the result. That is agreement as far as this surface can see it — nothing here compares two answers for meaning, so "nobody objected" is the strongest claim available.',
        };

        $matrix = $responses->map(fn (ConsensusResponse $response): string => sprintf(
            '- `%s` / `%s` — **%s**%s%s',
            $response->agent,
            $response->language,
            $response->status,
            $response->confidence === null ? '' : sprintf(' (stated confidence %s)', $response->confidence),
            is_string($response->dissent) && trim($response->dissent) !== '' ? ' — DISSENTED' : '',
        ))->implode("\n");

        $synthesis = is_string($run->synthesis) && trim($run->synthesis) !== ''
            ? "\n\nThe human synthesis, verbatim:\n\n".trim($run->synthesis)
            : '';

        return $headline
            ."\n\nQuestion asked:\n\n> ".$this->question($run)
            ."\n\nThe roster as it answered:\n\n".($matrix === '' ? '- (no agent was addressable)' : $matrix)
            .$synthesis;
    }

    /** @param Collection<int, ConsensusResponse> $responses */
    private function evidence(ConsensusRun $run, Collection $responses): string
    {
        $lines = $responses->map(function (ConsensusResponse $response): string {
            $answer = is_string($response->answer) ? trim($response->answer) : '';
            $dissent = is_string($response->dissent) ? trim($response->dissent) : '';

            return sprintf(
                '`%s` (%s) %s%s%s%s',
                $response->agent,
                $response->language,
                $response->status,
                $response->confidence === null ? ' — no confidence stated' : sprintf(' — confidence %s', $response->confidence),
                $answer === '' ? ' — returned no text' : ' — '.mb_substr($answer, 0, 600),
                $dissent === '' ? '' : "\n    dissent: ".mb_substr($dissent, 0, 400),
            );
        })->implode("\n");

        // The digest is what makes this run re-checkable: it pins the evidence
        // brief the agents were shown, so a later reader can tell whether two
        // runs were asked the same thing or merely a similar one.
        $header = sprintf(
            'consensus run `%s`, status `%s`, evidence digest `%s`.',
            $run->id, $run->status, (string) $run->evidence_digest,
        );

        return $header."\n\n".($lines === '' ? 'No agent was addressable, so nothing was collected.' : $lines);
    }

    /** @param Collection<int, ConsensusResponse> $responses */
    private function whyItMatters(ConsensusRun $run, Collection $responses): string
    {
        $answered = $responses->where('status', 'responded');
        $unavailable = $responses->where('status', '!=', 'responded');

        if ($responses->isEmpty()) {
            return 'A consensus request that reaches nobody looks identical, from the outside, to one that reached everybody and heard silence. Recording which it was stops the next person diagnosing the agents when the roster is what is wrong.';
        }

        if ($answered->isEmpty()) {
            return sprintf(
                'A run where NO agent answered is the one most worth writing down, and it is the one that used to leave nothing at all. The cost of a consensus request is in framing the question and standing the lanes up; unrecorded, the next person pays that again to rediscover that %s %s down.',
                $unavailable->pluck('agent')->implode(' and '),
                $unavailable->count() === 1 ? 'was' : 'were',
            );
        }

        if ($run->status === 'abandoned') {
            return 'An abandoned run is the quietest kind of waste: every agent was called and paid for, opinions exist, and no conclusion was ever drawn from them. Writing down what was collected and that nobody synthesised it is what stops the same question being asked again next week as though it had never been put.';
        }

        if ($this->dissenting($responses)->isNotEmpty()) {
            return 'Declared dissent is the single most valuable thing this surface produces and the easiest to lose. A synthesis flattens it by design, so unless the dissent is recorded separately the disagreement survives only for as long as somebody remembers reading it — and the whole point of collecting independent opinions is that the minority one might be the correct one.';
        }

        if ($unavailable->isNotEmpty()) {
            return sprintf(
                'The synthesis here rests on %d of %d agents, because %s did not answer. A conclusion drawn from a partial roster reads exactly like one drawn from a full one, and the difference matters: an absent language is not a consenting language.',
                $answered->count(), $responses->count(), $unavailable->pluck('agent')->implode(' and '),
            );
        }

        return 'Recorded so the conclusion can be read later without re-running the request, and so the raw independent answers survive the synthesis that summarised them.';
    }

    /**
     * @param  Collection<int, ConsensusResponse>  $responses
     */
    private function whatShouldChange(ConsensusRun $run, Collection $responses): ?string
    {
        $unavailable = $responses->where('status', '!=', 'responded');
        $dissenting = $this->dissenting($responses);
        $parts = [];

        if ($unavailable->isNotEmpty()) {
            $parts[] = sprintf(
                'These lanes were unreachable and contributed no opinion: %s. Check them on /lab/team before this run is cited as covering their languages.',
                $unavailable->map(fn (ConsensusResponse $r): string => sprintf('%s (%s)', $r->agent, $r->language))->implode(', '),
            );
        }

        if ($dissenting->isNotEmpty()) {
            $parts[] = sprintf(
                'Dissent was declared by %s and must stay legible in anything derived from this run — a summary that reads as unanimous is a summary that has lost the finding.',
                $dissenting->pluck('agent')->implode(', '),
            );
        }

        if ($run->status === 'abandoned') {
            $parts[] = is_string($run->abandon_reason) && trim($run->abandon_reason) !== ''
                ? 'Abandoned because: '.trim($run->abandon_reason).' Re-asking without reading the answers above costs the same calls again.'
                : 'No reason was given for abandoning this run. Re-asking without reading the answers above costs the same calls again.';
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /** @param Collection<int, ConsensusResponse> $responses */
    private function severity(ConsensusRun $run, Collection $responses): Severity
    {
        return match (true) {
            $responses->where('status', 'responded')->isEmpty() => Severity::Urgent,
            $this->dissenting($responses)->isNotEmpty() => Severity::Notable,
            $run->status === 'abandoned' => Severity::Notable,
            $responses->where('status', '!=', 'responded')->isNotEmpty() => Severity::Notable,
            default => Severity::Info,
        };
    }

    /**
     * The agents that DECLARED a dissent.
     *
     * Declared, not detected. Two agents whose answers differ in wording have
     * not disagreed as far as this surface is concerned, and pretending
     * otherwise would put a machine-invented disagreement into the knowledge
     * base under a human-sounding sentence.
     *
     * @param  Collection<int, ConsensusResponse>  $responses
     * @return Collection<int, ConsensusResponse>
     */
    private function dissenting(Collection $responses): Collection
    {
        return $responses->filter(fn (ConsensusResponse $response): bool => is_string($response->dissent) && trim($response->dissent) !== '');
    }
}
