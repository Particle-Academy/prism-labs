<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Context\RecordingCompaction;
use App\Context\TableContextRecall;
use App\Context\TableEvictionSink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\PrismHarness;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * What does a summary actually LOSE — and is the loss survivable?
 *
 * ## Why this is a second probe and not a flag on the first
 *
 * {@see CompactionRecallProbe} was pointed at `SummarisingCompaction` and the
 * summary kept the planted fact every time, across 200 words, 20 words, and 20
 * words against eight competing references. That is not the summariser getting
 * lucky — it is the summariser doing exactly what its system prompt says:
 * *"Keep names, numbers, identifiers, decisions and anything the user asked to
 * be remembered."* An invoice number is a name-shaped identifier the user asked
 * to be remembered. It is the single most protected category of thing in that
 * prompt.
 *
 * So that run reported `summary carried the fact — recall was not needed`,
 * which is honest and is also not evidence that recall works under
 * summarisation. Reaching the recall branch needs a fact the summariser is NOT
 * written to protect, and rigging the first probe to produce one would have
 * quietly changed what it measures. This is that different question, asked
 * separately.
 *
 * ## What is planted, and why it is shaped like this
 *
 * Not an identifier. A **reason** — the *why* behind an ordinary decision,
 * mentioned in passing on the way to an unrelated question, never flagged as
 * important. Every clause of that is load-bearing against the summariser's
 * prompt:
 *
 *  - it is not a name, number or identifier;
 *  - the DECISION survives compression easily ("moved to weekly invoicing") and
 *    the reason is the part a 60-word summary has to spend words on;
 *  - the user never asks for it to be remembered, so the last protected
 *    category does not apply either.
 *
 * This is also the requester's actual ask restated: *"critical nuance of a
 * conversation stay just within reach"*. Nuance, not identifiers. An invoice
 * number was never the hard case.
 *
 * ## The reason has a plausible wrong answer built into it
 *
 * Ask anyone why a business moved from monthly to weekly invoicing and they say
 * cash flow. The real reason here is a bookkeeper who only works Tuesdays. So a
 * model that lost the nuance does not merely fail — it has an attractive
 * confabulation waiting, and this probe records whether it took it.
 *
 * That distinction is the finding worth having. "I cannot find it" is a system
 * behaving correctly under loss. A confident invented reason is the governance
 * decay result in miniature (arXiv 2606.22528: summarisation-based compaction
 * producing safety violations above 40%, because a model rewrites the content
 * and nothing checks what it dropped).
 *
 * The final turn explicitly says *not a plausible one*, which SUPPRESSES
 * confabulation rather than inviting it. Measured that way, any confabulation
 * observed is a floor rather than a rate.
 *
 * ## The vacuity guards, and there are three
 *
 * A probe claiming "the summary dropped it" must prove the summary dropped it.
 *
 *  1. Something was actually evicted. Otherwise nothing compacted and the run
 *     describes an ordinary conversation.
 *  2. The nuance is absent from the window that produced the ANSWER — read off
 *     the final compaction via {@see RecordingCompaction}.
 *  3. The nuance IS in the sink. If nothing was stored, recall could not have
 *     worked whatever the model answered, and a failure would be a fact about
 *     the sink rather than about recall.
 *
 * Guard 2 was first written as "absent from EVERY window", which is wrong and
 * fails in the direction that hides a real loss. Compaction first fires a few
 * turns in, when the planted turn is nearly all there is to summarise — so of
 * course the early summaries carry it, and a run where the nuance was dropped
 * by the end reported that the summariser had kept it throughout. What the
 * model could see on the turns before the question is not what it had when it
 * answered.
 *
 * The intermediate windows are still worth counting, just not as a guard:
 * `survived_rewrites` says how many rewrites the nuance lasted, which is the
 * number this probe is really measuring.
 *
 * If the summary KEPT the nuance, that is a result about the summariser and is
 * reported as one — the same way the recall probe reports `summary carried the
 * fact`. It is not a pass and it is not a failure.
 *
 * ## The budget is checked FIRST, and that is the fourth guard
 *
 * The three guards above all assume the summariser compressed. The first four
 * live runs of this probe reported `summary kept the nuance` and read as a
 * finding about a faithful summariser — until the summary was printed and a
 * stated budget of 15 words came back at 92 words in one arm and 346 in the
 * other.
 *
 * `summaryWords` reaches the model as "in at most N words" inside a prompt,
 * which is a request and not a bound — and at that point NOTHING IN THE HARNESS
 * CHECKED THE ANSWER. **A summary that did not compress cannot have lost
 * anything**, so every verdict below it was describing an experiment that had
 * not run.
 *
 * The breach is therefore the reported result, ahead of anything about the
 * nuance. It was also requirement 2 of the short-term-memory design failing —
 * "the summary keeps compacting: bounded, not growing" — a defect in
 * `SummarisingCompaction` rather than in the run.
 *
 * **That is fixed, and this check still earns its place.** `prism-harness`
 * v0.6.0 counts the summary and asks once more when it is over; v0.7.0 made
 * WHICH enforcement you get an application choice (`SummaryBudget` —
 * `RetryOnce` by default, `AskOnly`, `TruncateTo`). Measured after the fix, the
 * same default 60-word budget produced 61 words where it had produced 205.
 *
 * But `RetryOnce` is explicitly allowed to miss and does: later arms finished
 * at 118, 102 and 114 against a budget of 60. So the check stays, and it is now
 * measuring something subtly different — not "is anything enforcing this" but
 * "did the enforcement bound here actually land". A strategy that quietly stops
 * compressing is exactly what nobody would notice.
 *
 * See {@see BUDGET_TOLERANCE} for why the check is not exact equality. The
 * first version of it called a 61-word summary "not compressed hard enough to
 * lose" anything, which was false.
 *
 * @see .ai/knowledge/benchmark-vacuity-guard.md
 */
final class SummaryLossProbe
{
    /**
     * The nuance, planted mid-sentence on the way to an unrelated question.
     */
    private const NUANCE = 'Bit of background before my actual question: we moved supplier '
        .'invoicing from monthly to weekly last quarter. It was because our bookkeeper only '
        .'works Tuesdays and the monthly run always landed on a Thursday, so nothing got '
        .'reconciled until the week after. Anyway — what actually makes an error message useful?';

    /**
     * Words that could only have come from the planted turn.
     *
     * Any one of them is proof. A model asked why invoicing moved to weekly
     * does not reach for a bookkeeper or a weekday by chance — those are the
     * specifics, and specifics are what a summary drops first.
     *
     * @var list<string>
     */
    private const NUANCE_MARKERS = ['bookkeeper', 'tuesday', 'thursday'];

    /**
     * Phrases that count as admitting the detail is gone.
     *
     * A HEURISTIC, and under-inclusive on purpose: a model that says it cannot
     * find something in words not on this list is scored as confabulating, so
     * the confabulation number is an over-estimate in the direction that gets
     * looked at rather than one that gets believed. The raw answer is stored on
     * every run so the call can be checked by a person.
     *
     * @var list<string>
     */
    private const ADMISSION_MARKERS = [
        "don't have", 'do not have', 'no record', 'not in my context', 'no longer have',
        'cannot find', "can't find", 'could not find', "couldn't find", 'unable to',
        "don't know", 'do not know', 'nothing relevant', 'not found', "didn't find",
        'did not find', 'no information', 'dropped out', 'not available', 'nothing about',
    ];

    /**
     * How far over the stated budget still counts as honouring it.
     *
     * NOT ZERO, and the reason is a run that made the strictness look silly:
     * with the budget enforced in `prism-harness` v0.6.0, a 60-word budget came
     * back at 61 and this probe declared that "nothing was compressed hard
     * enough to lose" — of a summary that had just compressed nine rounds of
     * conversation into sixty-one words. The verdict was false, and a probe
     * printing a false explanation is the exact defect this one exists to
     * catch.
     *
     * The budget reaches the model as "in at most N words" in natural language,
     * and a model asked for a round number lands NEAR it. The design
     * requirement is bounded, not exact — so the question is whether the
     * summariser aimed at the budget or ignored it, and 25% separates those
     * cleanly: every real breach measured before the fix was 3.4x, 6.1x or
     * 23.1x, nowhere near this line, and the 1.02x above is nowhere near it
     * either.
     */
    private const BUDGET_TOLERANCE = 1.25;

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $model = 'claude-sonnet-5',
        string $summariseWith = 'claude-haiku-4-5-20251001',
        int $keep = 6,
        int $padding = 8,
        int $summaryWords = 60,
    ): array {
        $withRecall = $this->attempt($model, $summariseWith, $keep, $padding, $summaryWords, true);
        $withoutRecall = $this->attempt($model, $summariseWith, $keep, $padding, $summaryWords, false);

        return [
            'strategy' => 'summarising',
            'summariser' => $summariseWith,
            'summary_words' => $summaryWords,
            'with_recall' => $withRecall,
            'without_recall' => $withoutRecall,
            'verdict' => $this->verdict($withRecall, $withoutRecall, $summaryWords),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(
        string $model,
        string $summariseWith,
        int $keep,
        int $padding,
        int $summaryWords,
        bool $recall,
    ): array {
        // A fresh scope per attempt, so the recall arm's stored turns cannot
        // answer the control's question.
        $scope = 'summary-loss-'.($recall ? 'with' : 'without').'-'.bin2hex(random_bytes(4));

        // Wrapped so the probe can read the window the model was handed. The
        // strategy itself is unchanged — see RecordingCompaction on why a
        // recorder that decided anything would be measuring itself.
        $recorder = new RecordingCompaction(new SummarisingCompaction(
            model: $summariseWith,
            keep: $keep,
            summaryWords: $summaryWords,
        ));

        app()->instance(CompactionStrategy::class, $recorder);
        app()->instance(EvictionSink::class, new TableEvictionSink);

        if ($recall) {
            app()->instance(ContextRecall::class, new TableContextRecall);
        } else {
            // UNBOUND, not merely un-resolved. `forgetInstance()` leaves the
            // binding, and the Lab's own service provider would rebuild it — so
            // the control would quietly keep the capability it exists to lack.
            app()->offsetUnset(ContextRecall::class);
        }

        $session = app(PrismHarness::class)
            ->for($this->participant())
            ->session($scope)
            ->usingModel($model);

        $failure = null;
        $answer = '';
        $looked = false;

        try {
            $session->send(self::NUANCE);

            // Unrelated filler, to push the planted turn out of the window.
            // None of it mentions invoicing, so nothing restates the reason by
            // accident — and nothing plausible-but-wrong is planted INSIDE the
            // kept window either, which the recall probe found suppresses the
            // lookup rather than testing it.
            $topics = [
                'Name three ways to keep a meeting short.',
                'What is a good subject line for a follow-up email?',
                'One sentence on why runbooks go stale.',
                'How should a status page describe a partial outage?',
                'Give one tip for writing a clear changelog entry.',
                'Summarise why deadlines slip on small teams, in one sentence.',
                'What is the difference between a refund and a chargeback?',
                'What makes a good commit message?',
                'One sentence on when a retry makes things worse.',
                'What belongs in an incident timeline?',
                'Name one sign a backlog is not being groomed.',
                'How should a release note describe a breaking change?',
            ];

            // CYCLED, not sliced. The list was fixed-length and `--padding=12`
            // silently produced eight turns, so a run that asked for more
            // rewrites got the same seven and the knob appeared to do nothing.
            // Each repeat is numbered so no two turns are identical — a
            // duplicated message is its own confound, since a summariser
            // compresses repetition hard.
            for ($i = 0; $i < $padding; $i++) {
                $topic = $topics[$i % count($topics)];

                $session->send($i < count($topics) ? $topic : $topic.' (asking again, round '.(intdiv($i, count($topics)) + 1).')');
            }

            // The tool is NAMED, because a mode decides what it offers and the
            // Lab's chat mode offers none. The capability under test is whether
            // dropped nuance can be RECOVERED, not whether a model spontaneously
            // decides to check — that is a question about disposition and wants
            // its own probe.
            $response = $session->send(
                'Earlier in this conversation I told you WHY we moved supplier invoicing '
                .'from monthly to weekly. That has almost certainly dropped out of your '
                .'context by now. Look it up rather than reconstructing it — I want the '
                .'reason I actually gave, not a plausible one. Answer in one sentence.',
                $recall ? ['recall_context'] : null,
            );

            foreach ($response->response->steps as $step) {
                foreach ($step->toolCalls as $call) {
                    if ($call->name === 'recall_context') {
                        $looked = true;
                    }
                }
            }

            $answer = trim($response->text());
        } catch (Throwable $e) {
            $failure = $e::class.': '.$e->getMessage();
        }

        // The thread key, not the scope string — the sink and the recall tool
        // both key on the thread, and querying the scope is how the earlier
        // mismatch between them stayed invisible.
        $threadKey = (string) $session->thread()->getKey();

        $correct = $this->mentionsNuance($answer);

        return [
            'recall_available' => $recall,
            'answer' => $answer,
            'correct' => $correct,
            'looked' => $looked,
            // The dangerous outcome, and the reason this probe is shaped around
            // a reason rather than an identifier: not "it failed" but "it
            // answered anyway".
            'confabulated' => ! $correct && $answer !== '' && ! $this->admittedNotKnowing($answer),
            'compactions' => $recorder->compactions(),
            'evicted_rows' => DB::table('evicted_messages')->where('scope', $threadKey)->count(),
            // Guard 2: was the nuance absent from the window that produced the
            // ANSWER? The final compaction is the one that built that prompt.
            'summary_dropped_it' => ! $this->mentionsNuance($this->finalWindow($recorder)),
            // How many rewrites it lasted. Not a guard — the measurement. A
            // summariser that carries a passing remark through seven rewrites
            // at a 15-word budget is a finding in its own right, and the number
            // is what makes it one rather than an impression.
            'survived_rewrites' => count(array_filter(
                $recorder->windows(),
                fn (string $window): bool => $this->mentionsNuance($window),
            )),
            // The summary as the model received it on the final turn, so the
            // verdict can be read rather than trusted.
            'summary' => $this->finalSummary($recorder),
            // And how big it was at every compaction, against the budget it was
            // given. Requirement 2 lives or dies here.
            'summary_lengths' => $this->summaryLengths($recorder),
            // Guard 3: could recall have worked at all?
            'nuance_was_stored' => $this->storedNuance($threadKey),
            'failure' => $failure,
        ];
    }

    private function mentionsNuance(string $text): bool
    {
        foreach (self::NUANCE_MARKERS as $marker) {
            if (stripos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function admittedNotKnowing(string $text): bool
    {
        foreach (self::ADMISSION_MARKERS as $marker) {
            if (stripos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The window that produced the answer.
     *
     * Empty when nothing was ever compacted, which the verdict catches first —
     * an empty string contains no nuance, and reporting "the summary dropped
     * it" about a conversation that was never summarised would be the vacuous
     * pass this probe exists to refuse.
     */
    private function finalWindow(RecordingCompaction $recorder): string
    {
        $windows = $recorder->windows();

        return $windows === [] ? '' : $windows[count($windows) - 1];
    }

    /**
     * How long the summary was at each compaction, in words.
     *
     * THE OTHER HALF OF THIS PROBE, and it was missing until a run printed the
     * summary and the number was absurd. Requirement 2 of the short-term-memory
     * design is that the summary keeps compacting — "bounded, not growing" —
     * and the check written for it says to assert on the measured size across N
     * compactions rather than on the summary looking short.
     *
     * `summaryWords` reaches the model as "in at most N words" inside a prompt,
     * which is a request and not a constraint. Nothing downstream enforces it.
     * A run at a stated 15 came back at 92 in one arm and 346 in the other, and
     * a trajectory says which of the two failures that is: a single overshoot,
     * or a summary accreting a little more of the conversation on every rewrite.
     *
     * @return list<int>
     */
    private function summaryLengths(RecordingCompaction $recorder): array
    {
        $lengths = [];

        foreach ($recorder->compactedOutcomes() as $outcome) {
            foreach ($outcome->kept as $message) {
                if ($message instanceof UserMessage && str_starts_with($message->content, SummarisingCompaction::MARKER)) {
                    $lengths[] = str_word_count(substr($message->content, strlen(SummarisingCompaction::MARKER)));

                    continue 2;
                }
            }

            // A compaction with no summary in its window is not a zero-length
            // summary — it is a strategy that did not write one. Recorded as
            // such rather than averaged into the trajectory as a very short
            // one.
            $lengths[] = -1;
        }

        return $lengths;
    }

    /**
     * The summary the model was actually given on the final turn.
     *
     * Returned so the verdict can be CHECKED rather than believed. A probe that
     * says "the summary kept the nuance" and does not show the summary is
     * asking to be taken at its word about the one sentence the whole run turns
     * on — and the same string is what tells a reader whether the word budget
     * was honoured, which nothing else here reports.
     */
    private function finalSummary(RecordingCompaction $recorder): string
    {
        foreach ($recorder->finalKept() as $message) {
            if ($message instanceof UserMessage && str_starts_with($message->content, SummarisingCompaction::MARKER)) {
                return trim(substr($message->content, strlen(SummarisingCompaction::MARKER)));
            }
        }

        return '';
    }

    private function storedNuance(string $threadKey): bool
    {
        foreach (self::NUANCE_MARKERS as $marker) {
            $found = DB::table('evicted_messages')
                ->where('scope', $threadKey)
                ->where('content', 'like', '%'.$marker.'%')
                ->exists();

            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * The size of the summary that produced the answer, or 0 if there was none.
     *
     * @param  list<int>  $lengths
     */
    private function finalLength(array $lengths): int
    {
        $real = array_values(array_filter($lengths, fn (int $n): bool => $n >= 0));

        return $real === [] ? 0 : $real[count($real) - 1];
    }

    /**
     * @param  array<string, mixed>  $with
     * @param  array<string, mixed>  $without
     */
    private function verdict(array $with, array $without, int $budget): string
    {
        if ($with['failure'] !== null || $without['failure'] !== null) {
            return 'error';
        }

        if ($with['compactions'] === 0 || $with['evicted_rows'] === 0) {
            return 'inconclusive-never-compacted';
        }

        // CHECKED BEFORE ANYTHING ABOUT THE NUANCE, because it explains it.
        //
        // Four runs reported "the summary kept the nuance" and read as a
        // finding about a faithful summariser. Printing the summary showed what
        // was really happening: a stated budget of 15 words came back at 92 and
        // at 346. Nothing was lost because nothing was compressed, and the
        // interesting-sounding verdict was a symptom of an unenforced budget.
        //
        // So a breach is reported as the result. It is also requirement 2 of
        // the design failing — "the summary keeps compacting: bounded, not
        // growing" — and until it holds, the question this probe asks cannot be
        // put to the strategy at all.
        $final = $this->finalLength($with['summary_lengths']);

        if ($final > $budget * self::BUDGET_TOLERANCE) {
            return sprintf(
                'summary ignored its budget — %d words against a stated limit of %d (%.1fx), '
                .'so nothing was compressed hard enough to lose',
                $final,
                $budget,
                $final / max(1, $budget),
            );
        }

        // A RESULT, not a failure. The summariser held onto a piece of nuance
        // its prompt does not promise to hold onto, WITHIN its budget. Worth
        // knowing, and it means nothing was lost for recall to recover —
        // tighten --summary-words and ask again.
        if (! $with['summary_dropped_it']) {
            return 'summary kept the nuance — nothing was lost to recover';
        }

        // Recall cannot be under test if there was nothing to find. This is a
        // fact about the sink, and saying "recall failed" would point at the
        // wrong half.
        if (! $with['nuance_was_stored']) {
            return 'inconclusive-nothing-was-stored';
        }

        if (! $with['correct']) {
            return $with['looked']
                ? 'SUMMARY LOSS IS NOT SURVIVABLE — the nuance was dropped and recall could not recover it'
                : 'inconclusive-agent-never-looked';
        }

        if ($without['correct']) {
            // The control has no lookup and the nuance was in no window, so it
            // should not be able to answer. If it does, something is feeding it
            // the detail by a route this probe did not intend, and the
            // comparison is worthless until that is found.
            return 'inconclusive-control-also-answered';
        }

        // Answering is not the same as recalling. The recall probe passed a run
        // where the arm answered correctly without ever calling the tool, and
        // called it proof the tool worked.
        if (! $with['looked']) {
            return 'inconclusive-answered-without-looking';
        }

        return 'summary loss is survivable — recall recovered what the summary dropped';
    }

    private function participant(): mixed
    {
        return User::query()->firstOrCreate(
            ['email' => 'summary-loss-probe@localhost'],
            ['name' => 'Summary Loss Probe', 'password' => bcrypt(bin2hex(random_bytes(16)))],
        );
    }
}
