<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Local-only benchmark history for the Prism Lab.
 *
 * Every test-suite run is appended as one JSON line under `storage/app`, so
 * benchmarks accumulate across sessions without needing a migration. Aggregates
 * group by provider + model + feature to compare latency, tokens, and cost.
 */
final class BenchmarkStore
{
    private const PATH = 'lab/benchmarks.jsonl';

    /**
     * Append the results of a suite run.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    public function record(array $results): void
    {
        $stamp = now()->toIso8601String();

        $lines = collect($results)
            ->map(fn (array $result): string => (string) json_encode([
                'recorded_at' => $stamp,
                'id' => $result['id'] ?? null,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'feature' => $result['feature'] ?? null,
                'passed' => (bool) ($result['passed'] ?? false),
                'latency_ms' => $result['latency_ms'] ?? null,
                'prompt_tokens' => $result['metrics']['prompt_tokens'] ?? null,
                'completion_tokens' => $result['metrics']['completion_tokens'] ?? null,
                'cost' => $result['metrics']['provider_reported_cost'] ?? null,
            ], JSON_UNESCAPED_SLASHES))
            ->implode(PHP_EOL);

        if ($lines === '') {
            return;
        }

        $existing = Storage::disk('local')->exists(self::PATH)
            ? rtrim((string) Storage::disk('local')->get(self::PATH))
            : '';

        Storage::disk('local')->put(self::PATH, ltrim($existing.PHP_EOL.$lines).PHP_EOL);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        if (! Storage::disk('local')->exists(self::PATH)) {
            return collect();
        }

        return collect(preg_split('/\R/', (string) Storage::disk('local')->get(self::PATH)) ?: [])
            ->reject(fn (string $line): bool => trim($line) === '')
            ->map(fn (string $line): mixed => json_decode($line, true))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
    }

    /**
     * Group runs by provider + model + feature with comparison metrics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregate(): array
    {
        return $this->all()
            ->groupBy(fn (array $row): string => sprintf('%s|%s|%s', $row['provider'] ?? '?', $row['model'] ?? '?', $row['feature'] ?? '?'))
            ->map(function (Collection $rows, string $key): array {
                [$provider, $model, $feature] = explode('|', $key);
                $latencies = $rows->pluck('latency_ms')
                    ->filter(fn (mixed $value): bool => is_numeric($value))
                    ->map(fn (mixed $value): float => (float) $value)
                    ->sort()->values();
                $passed = $rows->where('passed', true)->count();

                return [
                    'provider' => $provider,
                    'model' => $model,
                    'feature' => $feature,
                    'runs' => $rows->count(),
                    'passed' => $passed,
                    'pass_rate' => round($passed / max($rows->count(), 1) * 100, 1),
                    'avg_latency_ms' => $latencies->isEmpty() ? null : round((float) $latencies->avg(), 1),
                    'p95_latency_ms' => $this->percentile($latencies, 0.95),
                    'avg_prompt_tokens' => $this->average($rows, 'prompt_tokens'),
                    'avg_completion_tokens' => $this->average($rows, 'completion_tokens'),
                    'total_cost' => $this->total($rows, 'cost'),
                    'last_run' => $rows->max('recorded_at'),
                ];
            })
            ->sortBy(fn (array $row): string => $row['provider'].$row['feature'])
            ->values()
            ->all();
    }

    /**
     * A publishable artifact: the aggregated comparison plus its provenance.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $rows = $this->aggregate();

        return [
            'generated_at' => now()->toIso8601String(),
            'source' => 'Prism Lab — real provider generations (local)',
            'total_runs' => $this->all()->count(),
            'benchmarks' => $rows,
        ];
    }

    /** @param  Collection<int, float>  $sorted */
    private function percentile(Collection $sorted, float $quantile): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $index = (int) ceil($quantile * $sorted->count()) - 1;

        return round((float) $sorted->get(max($index, 0)), 1);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function average(Collection $rows, string $key): ?float
    {
        $values = $rows->pluck($key)->filter(fn (mixed $value): bool => is_numeric($value));

        return $values->isEmpty() ? null : round((float) $values->avg(), 1);
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function total(Collection $rows, string $key): ?float
    {
        $values = $rows->pluck($key)->filter(fn (mixed $value): bool => is_numeric($value));

        return $values->isEmpty() ? null : round((float) $values->sum(), 6);
    }
}
