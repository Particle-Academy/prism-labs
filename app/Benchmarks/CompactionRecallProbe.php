<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Context\TableContextRecall;
use App\Context\TableEvictionSink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Context\KeepRecentTurns;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\PrismHarness;
use Throwable;

/**
 * Can an agent still answer from a turn that left its context window?
 *
 * WHY THIS EXISTS AND WHY IT IS NOT ANOTHER UNIT TEST. `prism-harness` has 225
 * passing tests for compaction, and not one of them proves the thing the
 * feature is for. They prove a strategy returns the right arrays, that a sink
 * is called, that tool pairs survive. None of them puts a fact into turn one,
 * compacts it away, and asks a real model for it back. Until something does,
 * this is a mechanism with unit tests rather than a capability with evidence,
 * and 225 green ticks should not be read as the second.
 *
 * ## The shape
 *
 * A fact is planted in the first turn — an invoice number nothing else in the
 * conversation contains. The chat is then padded well past the compaction
 * threshold, so the turn carrying it is evicted and is provably no longer in
 * the window. Then the agent is asked for it.
 *
 * ## What is deliberately made easy, and why
 *
 * The final turn ASSERTS that the fact exists and directs a lookup. That is not
 * the probe going soft: the capability under test is whether an evicted fact can
 * be RECOVERED, not whether a model spontaneously decides to check.
 *
 * Left neutral, the model does something more interesting and less useful here —
 * seeing nothing about a disputed charge in its compacted window, it concludes
 * the premise is fabricated and refuses: "that premise isn't real, so I won't
 * call a tool." Which is the same confident assertion of absence that makes
 * clearing without recall dangerous, and worth knowing, but it measures
 * disposition rather than plumbing. A separate probe should ask that question.
 *
 * ## The positive control is the whole experiment
 *
 * The same question is asked with **recall unbound**. If the agent answers
 * correctly there too, this probe is not measuring recall — it is measuring
 * whether the model can guess an invoice number, or whether the fact was still
 * in the window after all, and a green result would mean nothing.
 *
 * So the verdict is a PAIR, and the only outcome that says the layer works is
 * "answered with recall, failed without". Anything else is reported as
 * inconclusive with the reason attached, because the failure this ecosystem is
 * worst at noticing is a probe that passes for a reason unrelated to the thing
 * it names. See `.ai/knowledge/benchmark-vacuity-guard.md`.
 *
 * ## Two strategies, and the control means different things under each
 *
 * `--summarise-with` swaps `KeepRecentTurns` for `SummarisingCompaction`, which
 * is a different question rather than the same question run twice.
 *
 * Under `KeepRecentTurns` the older turns are simply gone, so a control that
 * answers correctly got the fact from somewhere the experiment did not intend
 * and the run proves nothing.
 *
 * Under a summariser those turns were replaced by prose a model wrote, and the
 * control still has no lookup — so the only way it can answer is that **the
 * summary carried the reference**. That is a finding about the summariser, not
 * a broken experiment, and it is reported as its own verdict rather than folded
 * into `inconclusive`.
 *
 * Both outcomes are worth having, and they answer different halves:
 *
 *  - summary KEPT it     → the summariser preserved the detail; recall unused.
 *  - summary DROPPED it, recall recovered it → the sink earning its place. A
 *    summary is a lossy view, and this is the run where the loss was survivable.
 */
final class CompactionRecallProbe
{
    /**
     * A fact no model could produce by guessing, and nothing else mentions.
     */
    private const SECRET = 'INV-4471-QK';

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $model = 'claude-sonnet-5',
        int $keep = 6,
        int $padding = 8,
        ?string $summariseWith = null,
        int $summaryWords = 200,
    ): array {
        $withRecall = $this->attempt($model, $keep, $padding, true, $summariseWith, $summaryWords);
        $withoutRecall = $this->attempt($model, $keep, $padding, false, $summariseWith, $summaryWords);

        return [
            'strategy' => $summariseWith === null ? 'keep_recent' : 'summarising',
            'with_recall' => $withRecall,
            'without_recall' => $withoutRecall,
            'verdict' => $this->verdict($withRecall, $withoutRecall, $summariseWith !== null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(
        string $model,
        int $keep,
        int $padding,
        bool $recall,
        ?string $summariseWith = null,
        int $summaryWords = 200,
    ): array {
        // A fresh scope per attempt. Sharing one would let the first attempt's
        // evicted rows answer the second attempt's question, which would make
        // the control pass and the probe report failure for the wrong reason.
        $scope = 'recall-probe-'.($recall ? 'with' : 'without').'-'.bin2hex(random_bytes(4));

        // The strategy under test. `KeepRecentTurns` drops the older turns
        // outright; `SummarisingCompaction` replaces them with prose a model
        // wrote, which is a DIFFERENT question — see the verdict for why the
        // control means something else under each.
        app()->instance(CompactionStrategy::class, $summariseWith === null
            ? new KeepRecentTurns($keep)
            : new SummarisingCompaction(
                model: $summariseWith,
                keep: $keep,
                summaryWords: $summaryWords,
            ));
        app()->instance(EvictionSink::class, new TableEvictionSink);

        if ($recall) {
            app()->instance(ContextRecall::class, new TableContextRecall);
        } else {
            // UNBOUND, not merely un-resolved.
            //
            // `forgetInstance()` drops a resolved object and leaves the binding,
            // so the Lab's own `AppServiceProvider` registration simply built a
            // new one and the control arm kept the capability — it looked the
            // fact up and answered correctly, and the probe reported
            // `inconclusive-control-also-answered` rather than a false pass.
            //
            // The control has to genuinely not have recall. Anything less is
            // measuring two identical arms, which is the failure this probe
            // exists to avoid rather than to demonstrate.
            app()->offsetUnset(ContextRecall::class);
        }

        // The SCOPE is the session's, which is what the thread is keyed on and
        // therefore what the sink and the recall both see. A fresh one per
        // attempt keeps the control's conversation out of the recall arm's
        // reach -- sharing it would let the first attempt's evicted rows answer
        // the second attempt's question.
        $session = app(PrismHarness::class)
            ->for($this->participant())
            ->session($scope)
            ->usingModel($model);

        $failure = null;
        $answer = '';
        $looked = false;

        try {
            // Turn one carries the fact.
            $session->send(
                'Please note this for later: our reference for the disputed charge is '
                .self::SECRET.'. Just acknowledge it, briefly.'
            );

            // Padding, to push turn one out of the window. Each is deliberately
            // unrelated so nothing repeats the reference by accident.
            $topics = [
                'What is a good subject line for a follow-up email?',
                'Summarise why deadlines slip on small teams, in one sentence.',
                'Name three ways to keep a meeting short.',
                'What is the difference between a refund and a chargeback?',
                'Give one tip for writing a clear changelog entry.',
                'What makes an error message useful?',
                'How should a status page describe a partial outage?',
                'One sentence on why runbooks go stale.',
            ];

            // Padding, to push turn one out of the window. Each is
            // deliberately unrelated so nothing repeats the reference by
            // accident.
            //
            // COMPETING REFERENCES WERE TRIED HERE AND REMOVED. The idea was to
            // stop a correct answer being a lucky guess, and to force a tight
            // summary to choose. It did neither: the summariser kept the real
            // reference anyway, and because the decoys live in the turns that
            // STAY in the window, the model saw several plausible codes and
            // concluded it already had the answer — "I don't need to look this
            // up, I have the full conversation" — so the recall arm stopped
            // using the tool at all. A distractor placed inside the kept window
            // does not test recall, it suppresses it.
            foreach (array_slice($topics, 0, $padding) as $topic) {
                $session->send($topic);
            }

            // The tool is NAMED on the final turn.
            //
            // A mode decides which tools it offers and the Lab's `chat` mode
            // offers none, so a registered `recall_context` still never reaches
            // the model. That is correct behaviour -- a mode's tool list is the
            // application's decision -- and it is also the second reason the
            // first run of this probe reported RECALL FAILED with the fact
            // sitting in the table. The control arm names nothing, which is
            // what makes it a control.
            $response = $session->send(
                'Earlier in this conversation I gave you a reference for a disputed '
                .'charge. It has almost certainly dropped out of your context by now. '
                .'Look it up rather than telling me it never happened, then answer '
                .'with the reference only.',
                $recall ? ['recall_context'] : null,
            );

            // Whether it actually LOOKED.
            //
            // Without this, "answered incorrectly" collapses two different
            // results: the agent looked and recall could not find the fact, and
            // the agent never looked at all. Only the first says anything about
            // the recall layer, and the first run of this probe was the second
            // -- the model asserted the charge had never come up rather than
            // checking.
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

        // Queried on the THREAD key, which is what the sink is handed -- not
        // the session scope string. The first version of this probe used the
        // scope and reported "evicted rows 0" while 448 rows sat in the table,
        // which is how the mismatch between the sink and the recall tool was
        // found at all.
        $threadKey = (string) $session->thread()->getKey();

        $evicted = DB::table('evicted_messages')->where('scope', $threadKey)->count();
        $carriesSecret = DB::table('evicted_messages')
            ->where('scope', $threadKey)
            ->where('content', 'like', '%'.self::SECRET.'%')
            ->exists();

        return [
            'recall_available' => $recall,
            'answer' => $answer,
            'correct' => str_contains($answer, self::SECRET),
            'looked' => $looked,
            // The evidence that the fact really did leave the window. Without
            // it, "answered correctly" is equally consistent with the turn
            // never having been evicted, and the probe proves nothing.
            'evicted_rows' => $evicted,
            'secret_was_evicted' => $carriesSecret,
            'failure' => $failure,
        ];
    }

    /**
     * @param  array<string, mixed>  $with
     * @param  array<string, mixed>  $without
     */
    private function verdict(array $with, array $without, bool $summarising): string
    {
        if ($with['failure'] !== null || $without['failure'] !== null) {
            return 'error';
        }

        // Checked BEFORE the comparison. If the fact never left the window the
        // whole experiment is vacuous, and a "recall works" verdict off the
        // back of it would be the exact failure this file exists to avoid.
        if (! $with['secret_was_evicted']) {
            return 'inconclusive-never-evicted';
        }

        if (! $with['correct']) {
            // Two different failures, and only one is about the recall layer.
            return $with['looked']
                ? 'RECALL FAILED — the agent looked and the fact could not be recovered'
                : 'inconclusive-agent-never-looked';
        }

        if ($without['correct']) {
            // THE CONTROL ANSWERING MEANS DIFFERENT THINGS UNDER THE TWO
            // STRATEGIES, and collapsing them would throw away the finding.
            //
            // Under `KeepRecentTurns` the older turns are simply gone, so a
            // control that answers got the fact from somewhere this probe did
            // not intend — the experiment is not measuring recall and says so.
            //
            // Under a summariser the older turns were replaced by prose a model
            // wrote, and the control has no lookup — so the ONLY way it can
            // answer is that the summary carried the reference. That is a real
            // result about the summariser rather than a broken experiment: it
            // kept the detail, and recall was not needed on this run.
            return $summarising
                ? 'summary carried the fact — recall was not needed'
                : 'inconclusive-control-also-answered';
        }

        // ANSWERING CORRECTLY IS NOT THE SAME AS RECALL WORKING, and this probe
        // reported them as the same thing until a run showed both signals
        // disagreeing: the recall arm answered with the reference while
        // `looked` was false, and the verdict called it "recall works".
        //
        // `looked` was only ever consulted on FAILURE, so a correct answer that
        // never touched the tool passed as proof the tool worked. The control
        // failing is meant to rule that out, but the two arms are separate
        // conversations against a nondeterministic model — the control can fail
        // for its own reasons, and then a coincidence reads as a result.
        //
        // If the agent did not look, whatever saved it was not the recall
        // layer.
        if (! $with['looked']) {
            return 'inconclusive-answered-without-looking';
        }

        // Under a summariser this is the interesting one: the summary DROPPED
        // the reference, the control could not answer, and the arm that could
        // look it up did. That is the sink earning its place — a summary is a
        // lossy view, and this is the run where the loss was survivable.
        return 'recall works';
    }

    private function participant(): mixed
    {
        return User::query()->firstOrCreate(
            ['email' => 'recall-probe@localhost'],
            ['name' => 'Recall Probe', 'password' => bcrypt(bin2hex(random_bytes(16)))],
        );
    }
}
