<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Context\TableContextRecall;
use App\Context\TableEvictionSink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Context\KeepRecentTurns;
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
    public function run(string $model = 'claude-sonnet-5', int $keep = 6, int $padding = 8): array
    {
        $withRecall = $this->attempt($model, $keep, $padding, recall: true);
        $withoutRecall = $this->attempt($model, $keep, $padding, recall: false);

        return [
            'with_recall' => $withRecall,
            'without_recall' => $withoutRecall,
            'verdict' => $this->verdict($withRecall, $withoutRecall),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(string $model, int $keep, int $padding, bool $recall): array
    {
        // A fresh scope per attempt. Sharing one would let the first attempt's
        // evicted rows answer the second attempt's question, which would make
        // the control pass and the probe report failure for the wrong reason.
        $scope = 'recall-probe-'.($recall ? 'with' : 'without').'-'.bin2hex(random_bytes(4));

        app()->instance(CompactionStrategy::class, new KeepRecentTurns($keep));
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
    private function verdict(array $with, array $without): string
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
            // The control answered too, so something other than recall supplied
            // the fact. The probe is not measuring what it claims.
            return 'inconclusive-control-also-answered';
        }

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
