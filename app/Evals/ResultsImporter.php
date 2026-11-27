<?php

declare(strict_types=1);

namespace App\Evals;

use App\Models\EvalMetric;
use App\Models\EvalRun;
use App\Models\EvalTrial;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Imports one signed results.json from harness-bench.yml (G-393).
 *
 * Order matters: the attestation is verified BEFORE the file is parsed, so an
 * unsigned file is never read into the database at all. A second import of the
 * same approval is a no-op when the bytes are identical and refused when they
 * differ: an approval buys one result.
 */
final class ResultsImporter
{
    public const SCHEMA = 'prism-labs/harness-bench-results/1';

    private const STATUSES = ['solved', 'failed', 'cap_stopped', 'error', 'not_run'];

    private const NEUTRAL = ['terminal_bench' => 'terminus-2', 'tau2' => 'llm_agent'];

    public function __construct(private readonly AttestationVerifier $verifier) {}

    public function import(string $path): EvalRun
    {
        $absolute = realpath($path);
        if ($absolute === false || ! is_file($absolute)) {
            throw new EvalImportRefused('No results file at that path.');
        }
        if (filesize($absolute) > 64 * 1024 * 1024) {
            throw new EvalImportRefused('The results file is larger than 64 MiB.');
        }
        $this->verifier->verify($absolute);
        $bytes = (string) file_get_contents($absolute);
        $sha = hash('sha256', $bytes);
        try {
            $results = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EvalImportRefused('The results file is not JSON.');
        }
        if (! is_array($results) || ($results['schema'] ?? null) !== self::SCHEMA) {
            throw new EvalImportRefused('The results file is not '.self::SCHEMA.'.');
        }
        $ftq = (string) ($results['ftq_id'] ?? '');
        if (preg_match('/\Aq_[0-9a-f][0-9a-f-]{5,63}\z/', $ftq) !== 1) {
            throw new EvalImportRefused('The results file names no valid FTQ id.');
        }
        $existing = EvalRun::query()->where('ftq_id', $ftq)->first();
        if ($existing !== null) {
            if ($existing->results_sha256 === $sha) {
                return $existing;
            }
            throw new EvalImportRefused('This approval already has imported results with different bytes.');
        }

        return DB::transaction(fn (): EvalRun => $this->store($results, $ftq, $sha));
    }

    /** @param array<string, mixed> $results */
    private function store(array $results, string $ftq, string $sha): EvalRun
    {
        $run = EvalRun::query()->create([
            'ftq_id' => $ftq,
            'plan_path' => (string) ($results['plan_path'] ?? ''),
            'plan_digest' => (string) ($results['plan_digest'] ?? ''),
            'cap_usd' => (float) ($results['cap_usd'] ?? 0),
            'spent_usd' => (float) ($results['spent_usd'] ?? 0),
            'model' => (string) ($results['model']['id'] ?? ''),
            'reasoning_effort' => (string) ($results['model']['reasoning_effort'] ?? ''),
            'harness_version' => (string) ($results['harness']['version'] ?? ''),
            'workflow_run_id' => isset($results['run']['run_id']) ? (string) $results['run']['run_id'] : null,
            'commit_sha' => isset($results['run']['sha']) ? (string) $results['run']['sha'] : null,
            'results_sha256' => $sha,
            'attestation_verified_at' => now(),
        ]);
        foreach ($results['trials'] ?? [] as $trial) {
            if (! is_array($trial) || ! in_array($trial['status'] ?? null, self::STATUSES, true)) {
                throw new EvalImportRefused('A trial has an unknown status.');
            }
            $tokens = is_array($trial['tokens'] ?? null) ? $trial['tokens'] : [];
            EvalTrial::query()->create([
                'eval_run_id' => $run->id,
                'benchmark' => (string) $trial['benchmark'],
                'arm' => (string) $trial['arm'],
                'task_id' => (string) $trial['task_id'],
                'trial' => (int) ($trial['trial'] ?? 0),
                'status' => $trial['status'],
                'reward' => is_numeric($trial['reward'] ?? null) ? (float) $trial['reward'] : null,
                'cost_usd' => (float) ($trial['cost_usd'] ?? 0),
                'fresh_input_tokens' => (int) ($tokens['fresh_input'] ?? 0),
                'cache_write_tokens' => (int) ($tokens['cache_write'] ?? 0),
                'cache_read_tokens' => (int) ($tokens['cache_read'] ?? 0),
                'output_tokens' => (int) ($tokens['output'] ?? 0),
                'steps' => (int) ($trial['steps'] ?? 0),
                'wall_seconds' => is_numeric($trial['wall_seconds'] ?? null) ? (float) $trial['wall_seconds'] : null,
            ]);
        }
        foreach ($results['metrics'] ?? [] as $benchmark => $block) {
            $neutral = self::NEUTRAL[$benchmark] ?? null;
            foreach ($block['arms'] ?? [] as $arm => $summary) {
                $delta = $arm === $neutral ? null : ($block['deltas'][$arm.'_vs_'.$neutral] ?? null);
                EvalMetric::query()->create([
                    'eval_run_id' => $run->id,
                    'benchmark' => (string) $benchmark,
                    'arm' => (string) $arm,
                    'neutral' => $arm === $neutral,
                    'summary' => $summary,
                    'delta' => $delta,
                ]);
            }
        }

        return $run;
    }
}
