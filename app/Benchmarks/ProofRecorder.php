<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkLane;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProofRecorder
{
    /**
     * @param  array<string, mixed>  $proof
     * @param  list<array{kind:string,payload:array<string,mixed>}>  $receipts
     */
    public function complete(BenchmarkLane $lane, array $proof, array $receipts, ?float $score = null): BenchmarkLane
    {
        if ($lane->status !== 'running') {
            throw new \LogicException('Only a running benchmark lane can submit proof.');
        }
        foreach (['spec_digest', 'working_artifact', 'checks', 'zero_learning'] as $field) {
            if (! array_key_exists($field, $proof)) {
                throw new \InvalidArgumentException("Proof-of-Working requires [{$field}].");
            }
        }
        $expectedDigest = DB::table('benchmark_runs')
            ->join('benchmark_specs', 'benchmark_specs.id', '=', 'benchmark_runs.benchmark_spec_id')
            ->where('benchmark_runs.id', $lane->benchmark_run_id)
            ->value('benchmark_specs.digest');
        if (! is_string($expectedDigest) || ! is_string($proof['spec_digest']) || ! hash_equals($expectedDigest, $proof['spec_digest'])) {
            throw new \InvalidArgumentException('Proof-of-Working does not match the immutable benchmark specification.');
        }
        if ($score !== null && (! is_finite($score) || $score < 0 || $score > 100)) {
            throw new \InvalidArgumentException('Benchmark score must be between 0 and 100.');
        }
        if ($receipts === []) {
            throw new \InvalidArgumentException('Proof-of-Working requires at least one independently checkable receipt.');
        }

        DB::transaction(function () use ($lane, $proof, $receipts, $score): void {
            foreach ($receipts as $receipt) {
                $payload = $receipt['payload'];
                DB::table('benchmark_receipts')->insert([
                    'id' => (string) Str::uuid(),
                    'benchmark_lane_id' => $lane->id,
                    'kind' => $receipt['kind'],
                    'digest' => hash('sha256', CanonicalJson::encode($payload)),
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $lane->forceFill([
                'status' => 'completed', 'proof' => $proof, 'score' => $score,
                'finished_at' => now(),
                'duration_ms' => $lane->started_at?->diffInMilliseconds(now()),
            ])->save();
        });

        return $lane->refresh();
    }
}
