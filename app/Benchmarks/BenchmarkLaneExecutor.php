<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Team\AgentRoster;
use App\Team\LanguageAgent;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use Throwable;

final readonly class BenchmarkLaneExecutor implements NodeExecutor
{
    public function __construct(
        private LabSession $sessions,
        private AgentRoster $roster,
        private LaneActivity $activity,
        private LaneWorkspace $workspaces,
        private ProofRecorder $proofs,
        private BenchmarkRunReconciler $runs,
    ) {}

    /** @return array<string, mixed> */
    public function execute(ExecutionContext $ctx): array
    {
        $laneId = $ctx->option('lane_id');
        if (! is_string($laneId)) {
            throw new \InvalidArgumentException('Benchmark lane node requires lane_id.');
        }
        $lane = BenchmarkLane::query()->findOrFail($laneId);
        $run = BenchmarkRun::query()->findOrFail($lane->benchmark_run_id);
        if ($run->status === 'cancelled') {
            throw new \LogicException('Benchmark fuse is tripped; this lane may not start.');
        }
        $spec = BenchmarkSpec::query()->findOrFail($run->benchmark_spec_id);
        $lane->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $this->activity->record($lane, 'lane.started', sprintf('%s agent started.', $lane->language), ['provider' => $lane->provider, 'model' => $lane->model]);
        $ctx->emit(RunEvent::log('info', sprintf('%s lane started', $lane->language), $ctx->node->id));

        try {
            $result = $this->run($lane, $spec);
            if ($run->refresh()->status === 'cancelled') {
                return ['lane_id' => $lane->id, 'ok' => false, 'status' => 'cancelled'];
            }
            $ok = ($result['ok'] ?? true) === true;
            $reason = is_string($result['reason'] ?? null) ? $result['reason'] : null;
            $this->activity->record($lane, $ok ? 'agent.completed' : 'agent.failed', $ok ? 'Agent returned its lane result.' : ($reason ?? 'Agent returned a failed result.'), $this->boundedProof($result), $ok ? 'info' : 'error');
            if ($ok) {
                $this->recordProof($lane, $spec);
            } else {
                $lane->forceFill(['status' => 'failed', 'proof' => $this->boundedProof($result), 'finished_at' => now()])->save();
            }
            $this->runs->reconcile($run);

            return ['lane_id' => $lane->id, 'ok' => $ok, 'status' => $lane->status];
        } catch (Throwable $failure) {
            if ($run->refresh()->status === 'cancelled') {
                return ['lane_id' => $lane->id, 'ok' => false, 'status' => 'cancelled'];
            }
            $lane->forceFill(['status' => 'failed', 'proof' => ['failure_class' => $failure::class], 'finished_at' => now()])->save();
            $this->activity->record($lane, 'agent.exception', $failure->getMessage(), ['failure_class' => $failure::class], 'error');
            $this->runs->reconcile($run);
            throw $failure;
        }
    }

    /** @return array<string, mixed> */
    private function run(BenchmarkLane $lane, BenchmarkSpec $spec): array
    {
        $payload = ['spec_digest' => $spec->digest, 'specification' => $spec->specification, 'rubric' => $spec->rubric, 'budgets' => $spec->budgets, 'surface_mode' => $spec->surface_mode, 'workspace_path' => $lane->workspace_path];
        $this->activity->record($lane, 'agent.request', 'Frozen benchmark specification sent to the lane agent.', ['workspace' => $lane->workspace_path]);
        if ($lane->language === 'php') {
            $response = $this->sessions->resolveScope('benchmark:'.$lane->benchmark_run_id.':'.$lane->id)
                ->usingMode('benchmark')->usingProvider($lane->provider)->usingModel($lane->model)
                ->send('Execute this frozen Prism Lab benchmark lane. Build the requested artifact in the workspace. Before finishing, write PROOF_OF_WORKING.json with exactly these top-level fields: spec_digest (the supplied digest), working_artifact (relative path), checks (object), zero_learning (non-empty string), and receipts (a non-empty array of objects with kind and payload). Prose alone is not proof and the lane will fail closed if this file is missing or invalid. Specification: '.json_encode($payload, JSON_THROW_ON_ERROR));

            return ['ok' => true, 'text' => $response->text(), 'run_id' => $response->runId];
        }

        $language = match ($lane->language) {
            'typescript' => 'ts',
            'python' => 'py',
            default => $lane->language,
        };
        $agent = $this->roster->find($language);
        if ($agent === null) {
            return ['ok' => false, 'reason' => 'No parity agent is configured for this language.'];
        }

        return (new LanguageAgent($agent))->call('benchmark', $payload);
    }

    /** @param array<string, mixed> $result */
    private function boundedProof(array $result): array
    {
        return [
            'ok' => ($result['ok'] ?? false) === true,
            'run_id' => is_string($result['run_id'] ?? null) ? $result['run_id'] : null,
            'text' => mb_substr(is_string($result['text'] ?? null) ? $result['text'] : '', 0, 20_000),
            'unreachable' => ($result['unreachable'] ?? false) === true,
            'reason' => is_string($result['reason'] ?? null) ? mb_substr($result['reason'], 0, 4000) : null,
        ];
    }

    private function recordProof(BenchmarkLane $lane, BenchmarkSpec $spec): void
    {
        $file = $this->workspaces->read($lane, '/PROOF_OF_WORKING.json');
        $proof = json_decode($file['content'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($proof) || ! is_array($proof['receipts'] ?? null)) {
            throw new \InvalidArgumentException('PROOF_OF_WORKING.json must contain a receipts array.');
        }

        $receipts = $proof['receipts'];
        unset($proof['receipts']);
        foreach ($receipts as $receipt) {
            if (! is_array($receipt) || ! is_string($receipt['kind'] ?? null) || ! is_array($receipt['payload'] ?? null)) {
                throw new \InvalidArgumentException('Every proof receipt requires a string kind and object payload.');
            }
        }

        $this->proofs->complete($lane, $proof, $receipts);
        $this->activity->record($lane, 'proof.accepted', 'Digest-bound Proof-of-Working submitted for independent scoring.', [
            'spec_digest' => $spec->digest,
            'receipts' => count($receipts),
        ]);
    }
}
