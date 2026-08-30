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
 */
final class ConsensusCoordinator
{
    public function __construct(private readonly AgentRoster $roster) {}

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

        return $run->refresh();
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

        return $run->refresh();
    }
}
