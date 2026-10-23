<?php

declare(strict_types=1);

namespace App\Consensus;

use App\Benchmarks\CanonicalJson;
use App\Models\ConsensusResponse;
use App\Models\ConsensusRun;
use App\Team\AgentRoster;
use App\Team\LanguageAgent;
use Illuminate\Support\Facades\DB;

/**
 * Collects independent language-agent opinions and freezes them for review.
 * It deliberately cannot publish: review and delivery are separate capabilities.
 *
 * Every path that ENDS a run files a 0L through {@see ConsensusLearningRecorder}.
 * There are three of them and they used to file nothing between them, which is
 * the whole reason that class exists.
 */
final class ConsensusCoordinator
{
    public function __construct(
        private readonly AgentRoster $roster,
        private readonly ConsensusLearningRecorder $learnings,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function collect(string $question, array $evidence = []): ConsensusRun
    {
        return $this->collectPending($this->begin($question, $evidence), $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function begin(string $question, array $evidence = []): ConsensusRun
    {
        return ConsensusRun::query()->create([
            'question' => $question,
            'evidence_digest' => hash('sha256', CanonicalJson::encode($evidence)),
            'status' => 'collecting',
        ]);
    }

    /** @param array<string, mixed> $evidence */
    public function collectPending(ConsensusRun $run, array $evidence = []): ConsensusRun
    {
        if ($run->refresh()->status !== 'collecting') {
            return $run;
        }
        if (! hash_equals($run->evidence_digest, hash('sha256', CanonicalJson::encode($evidence)))) {
            throw new \LogicException('Collection evidence differs from the frozen brief.');
        }

        foreach ($this->roster->addressable() as $agent) {
            if ($run->refresh()->status !== 'collecting') {
                return $run;
            }
            if ($run->responses()->where('agent', $agent->name)->exists()) {
                continue;
            }
            $result = (new LanguageAgent($agent))->call('consensus', [
                'question' => $run->question,
                'evidence' => $evidence,
            ]);
            // A person may abandon collection while a model call is pending.
            if ($run->refresh()->status !== 'collecting') {
                return $run;
            }
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $answer = is_array($result['data'] ?? null) ? ($data['answer'] ?? null) : ($result['text'] ?? null);
            $responded = ($result['ok'] ?? false) === true && is_string($answer) && trim($answer) !== '';
            $reason = ($result['ok'] ?? false) === true
                ? 'The agent returned no usable answer.'
                : ($result['reason'] ?? $data['reason'] ?? $result['text'] ?? 'The agent returned no usable answer.');
            $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null;
            $stored = DB::transaction(function () use ($run, $agent, $responded, $answer, $reason, $data, $confidence): bool {
                $current = ConsensusRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($current->status !== 'collecting') {
                    return false;
                }
                ConsensusResponse::query()->create([
                    'consensus_run_id' => $run->id,
                    'agent' => $agent->name,
                    'language' => $agent->language,
                    'status' => $responded ? 'responded' : 'unavailable',
                    'answer' => $responded ? $answer : null,
                    'failure_reason' => $responded ? null : mb_substr(is_string($reason) && trim($reason) !== '' ? $reason : 'The agent returned no usable answer.', 0, 2000),
                    'evidence' => is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
                    'confidence' => $confidence !== null && is_finite($confidence) && $confidence >= 0 && $confidence <= 1 ? $confidence : null,
                    'dissent' => is_string($data['dissent'] ?? null) ? $data['dissent'] : null,
                ]);

                return true;
            });
            if (! $stored) {
                return $run->refresh();
            }
        }

        if (ConsensusRun::query()->whereKey($run->id)->where('status', 'collecting')->update(['status' => 'awaiting_review']) !== 1) {
            return $run->refresh();
        }
        $run = $run->refresh();

        // A collection that heard NOTHING is terminal on arrival: there is no
        // opinion for a human to weigh, so no synthesis is coming that could
        // add to the record. Waiting for a review that will never happen is
        // how the most valuable run — the one where every lane was down —
        // ended up being the one that left no trace.
        //
        // The status stays `awaiting_review` rather than being forced
        // terminal, because a human is still entitled to write down what they
        // did about it. The recorder is idempotent, so that later review adds
        // no second learning.
        if ($run->responses()->where('status', 'responded')->doesntExist()) {
            $this->learnings->record($run);
            $run = $run->refresh();
        }

        return $run;
    }

    public function review(ConsensusRun $run, string $synthesis): ConsensusRun
    {
        DB::transaction(function () use ($run, $synthesis): void {
            $current = ConsensusRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($current->status !== 'awaiting_review') {
                throw new \LogicException('Consensus must finish collection before human review.');
            }
            $current->forceFill([
                'status' => 'reviewed',
                'synthesis' => $synthesis,
                'reviewed_at' => now(),
            ])->save();
        });

        $reviewed = $run->refresh();

        // Outside the transaction, for the same reason the benchmark
        // reconciler does it there: a 0L that cannot be written must not roll
        // back the synthesis a human just typed.
        $this->learnings->record($reviewed);

        return $reviewed->refresh();
    }

    /**
     * Close a run that nobody is going to synthesise.
     *
     * This exists so that "abandoned unreviewed" is a state the surface can
     * REACH rather than merely a thing that happens. Before it, a run a human
     * looked at and walked away from stayed in `awaiting_review` forever,
     * indistinguishable from one they were about to open — so the calls it
     * spent were never written down anywhere.
     */
    public function abandon(ConsensusRun $run, string $reason = ''): ConsensusRun
    {
        DB::transaction(function () use ($run, $reason): void {
            $current = ConsensusRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($current->status, ['collecting', 'awaiting_review'], true)) {
                throw new \LogicException('Only a run still waiting on collection or review can be abandoned.');
            }
            $current->forceFill([
                'status' => 'abandoned',
                'abandoned_at' => now(),
                'abandon_reason' => trim($reason) === '' ? null : trim($reason),
            ])->save();
        });

        $abandoned = $run->refresh();
        $this->learnings->record($abandoned);

        return $abandoned->refresh();
    }
}
