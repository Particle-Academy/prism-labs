<?php

declare(strict_types=1);

namespace App\Telemetry;

use App\Models\LabOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

final class OperationLedger
{
    /** @param array<string, mixed> $attributes */
    public function start(string $kind, array $attributes = []): LabOperation
    {
        return LabOperation::query()->create([
            'id' => $attributes['id'] ?? (string) Str::uuid(),
            'parent_id' => $attributes['parent_id'] ?? null,
            'benchmark_run_id' => $attributes['benchmark_run_id'] ?? null,
            'benchmark_lane_id' => $attributes['benchmark_lane_id'] ?? null,
            'consensus_run_id' => $attributes['consensus_run_id'] ?? null,
            'harness_session' => $attributes['harness_session'] ?? null,
            'trace_id' => $attributes['trace_id'] ?? null,
            'kind' => $kind,
            'provider' => $attributes['provider'] ?? null,
            'model' => $attributes['model'] ?? null,
            'language' => $attributes['language'] ?? null,
            'status' => 'running',
            'cost_source' => 'unpriced',
            'metadata' => $attributes['metadata'] ?? null,
            'started_at' => $attributes['started_at'] ?? now(),
        ]);
    }

    public function startGeneration(TelemetryContext $context): LabOperation
    {
        $benchmark = $this->benchmarkIdentity($context->sessionId);

        return LabOperation::query()->firstOrCreate(['id' => $context->traceId], [
            'harness_session' => $context->sessionId,
            ...$benchmark,
            'trace_id' => $context->traceId,
            'kind' => 'generation.'.$context->operation->value,
            'provider' => $context->provider,
            'model' => $context->model,
            'status' => 'running',
            'cost_source' => 'unpriced',
            'started_at' => CarbonImmutable::createFromTimestamp($context->startedAt),
        ]);
    }

    public function completeGeneration(TelemetryContext $context, float $durationMs, ?Usage $usage, ?string $finishReason): void
    {
        $operation = $this->startGeneration($context);
        $operation->forceFill([
            'status' => 'completed', 'duration_ms' => (int) round($durationMs),
            ...$this->usage($usage),
            'metadata' => ['finish_reason' => $finishReason],
            'finished_at' => now(),
        ])->save();
    }

    public function failGeneration(TelemetryContext $context, float $durationMs, Throwable $failure): void
    {
        $operation = $this->startGeneration($context);
        $operation->forceFill([
            'status' => 'failed', 'duration_ms' => (int) round($durationMs),
            'failure_class' => $failure::class, 'finished_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $metadata */
    public function recordChild(TelemetryContext $context, string $kind, float $durationMs, array $metadata = [], ?Usage $usage = null): void
    {
        $this->startGeneration($context);
        $operation = $this->start($kind, [
            'parent_id' => $context->traceId, 'trace_id' => $context->traceId,
            'harness_session' => $context->sessionId, 'provider' => $context->provider,
            'model' => $context->model, 'metadata' => $metadata,
            ...$this->benchmarkIdentity($context->sessionId),
        ]);
        $operation->forceFill([
            'status' => 'completed', 'duration_ms' => (int) round($durationMs),
            ...$this->usage($usage), 'finished_at' => now(),
        ])->save();
    }

    /** @return array<string, int|float|null|string> */
    public function burn(string $period): array
    {
        $start = match ($period) {
            'daily' => now()->startOfDay(),
            'monthly' => now()->startOfMonth(),
            default => throw new \InvalidArgumentException('Burn period must be daily or monthly.'),
        };
        $query = LabOperation::query()->where('started_at', '>=', $start);
        $billable = (clone $query)->where(function ($query): void {
            $query->whereNotNull('input_tokens')->orWhereNotNull('output_tokens');
        });
        $total = (clone $billable)->count();
        $priced = (clone $billable)->where('cost_source', 'provider_reported')->count();

        return [
            'period' => $period,
            'input_tokens' => (int) (clone $query)->sum('input_tokens'),
            'output_tokens' => (int) (clone $query)->sum('output_tokens'),
            'reasoning_tokens' => (int) (clone $query)->sum('reasoning_tokens'),
            'priced_cost' => round((float) (clone $query)->where('cost_source', 'provider_reported')->sum('cost'), 8),
            'operations' => $total,
            'unpriced_operations' => $total - $priced,
            'cost_completeness' => $total === 0 ? null : round($priced / $total * 100, 2),
        ];
    }

    /** @return array<string, int|float|null|string> */
    private function usage(?Usage $usage): array
    {
        if (! $usage instanceof Usage) {
            return [];
        }

        return [
            'input_tokens' => $usage->promptTokens,
            'output_tokens' => $usage->completionTokens,
            'cache_write_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_tokens' => $usage->cacheReadInputTokens,
            'reasoning_tokens' => $usage->thoughtTokens,
            'cost' => $usage->cost,
            'cost_source' => $usage->cost === null ? 'unpriced' : 'provider_reported',
        ];
    }

    /** @return array{benchmark_run_id?:string,benchmark_lane_id?:string} */
    private function benchmarkIdentity(?string $sessionId): array
    {
        if (! is_string($sessionId) || preg_match('/benchmark:([0-9a-f-]{36}):([0-9a-f-]{36})$/i', $sessionId, $matches) !== 1) {
            return [];
        }

        return ['benchmark_run_id' => $matches[1], 'benchmark_lane_id' => $matches[2]];
    }
}
