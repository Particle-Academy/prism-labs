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
        $run = ConsensusRun::query()->create([
            'question' => $question,
            'evidence_digest' => hash('sha256', CanonicalJson::encode($evidence)),
            'status' => 'collecting',
        ]);

        foreach ($this->roster->addressable() as $agent) {
            $result = (new LanguageAgent($agent))->call('consensus', [
                'question' => $question,
                'evidence' => $evidence,
            ]);
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            ConsensusResponse::query()->create([
                'consensus_run_id' => $run->id,
                'agent' => $agent->name,
                'language' => $agent->language,
                'status' => ($result['ok'] ?? false) ? 'responded' : 'unavailable',
                'answer' => is_string($data['answer'] ?? null) ? $data['answer'] : ($result['text'] ?? null),
                'evidence' => is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
                'confidence' => is_numeric($data['confidence'] ?? null) ? $data['confidence'] : null,
                'dissent' => is_string($data['dissent'] ?? null) ? $data['dissent'] : null,
            ]);
        }

        $run->forceFill(['status' => 'awaiting_review'])->save();
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
        if ($run->status !== 'awaiting_review') {
            throw new \LogicException('Consensus must finish collection before human review.');
        }

        DB::transaction(function () use ($run, $synthesis): void {
            $run->forceFill([
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
        if (! in_array($run->status, ['collecting', 'awaiting_review'], true)) {
            throw new \LogicException('Only a run still waiting on collection or review can be abandoned.');
        }

        DB::transaction(function () use ($run, $reason): void {
            $run->forceFill([
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
