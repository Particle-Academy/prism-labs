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

    public function failGeneration(TelemetryContext $context, float $durationMs, Throwable $failure, ?object $failureUsage = null): void
    {
        $this->recordGenerationFailure($context, $durationMs, $failure, $failureUsage);
    }

    public function errorGeneration(TelemetryContext $context, float $durationMs, ?object $failureUsage = null): void
    {
        $this->recordGenerationFailure($context, $durationMs, null, $failureUsage);
    }

    private function recordGenerationFailure(TelemetryContext $context, float $durationMs, ?Throwable $failure, ?object $failureUsage): void
    {
        $operation = $this->startGeneration($context);
        $metadata = $operation->metadata;
        $snapshot = $this->failureSnapshot($failureUsage);
        if ($snapshot !== null) {
            $metadata = [...($metadata ?? []), 'failure_usage' => $snapshot];
            $this->recordFailedStep($context, $snapshot);
        }
        $operation->forceFill([
            'status' => 'failed', 'duration_ms' => (int) round($durationMs),
            'failure_class' => $failure === null ? $operation->failure_class : $failure::class,
            'input_tokens' => null, 'output_tokens' => null, 'cache_write_tokens' => null,
            'cache_read_tokens' => null, 'reasoning_tokens' => null, 'cost' => null,
            'cost_source' => 'unpriced', 'metadata' => $metadata, 'finished_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed>|null */
    private function failureSnapshot(?object $failure): ?array
    {
        $class = 'Prism\\Prism\\ValueObjects\\StreamFailureUsage';
        if ($failure === null || ! class_exists($class) || ! $failure instanceof $class) {
            return null;
        }
        $data = get_object_vars($failure);
        $usage = $data['usage'] ?? null;
        $fields = $data['reportedFields'] ?? null;
        $step = $data['stepIndex'] ?? null;
        if (! $usage instanceof Usage || ! is_array($fields) || ! is_int($step) || $step < 0) {
            return null;
        }
        $values = $usage->toArray();
        foreach ($values as $field => $value) {
            if (! (is_int($value) || is_float($value)) || ! is_finite((float) $value) || $value < 0) {
                $values[$field] = null;
            }
        }
        $reported = [];
        foreach ($fields as $field) {
            $value = is_string($field) ? ($values[$field] ?? null) : null;
            if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0) {
                $reported[] = $field;
            }
        }
        if ($reported === []) {
            return null;
        }

        return ['usage' => $values, 'reported_fields' => $reported, 'step_index' => $step,
            'status' => 'provisional', 'scope' => 'attempt', 'includes_completed_steps' => false];
    }

    /** @param array<string, mixed> $snapshot */
    private function recordFailedStep(TelemetryContext $context, array $snapshot): void
    {
        $step = $snapshot['step_index'];
        $operation = LabOperation::query()->where('parent_id', $context->traceId)
            ->where('kind', 'generation.step')->where('metadata->step_index', $step)->first();
        if ($operation === null) {
            // Stable primary key makes repeated failure notifications for this
            // attempt idempotent even when no StepCompleted event was emitted.
            $digest = hash('sha256', 'prism-failed-step:'.$context->traceId.':'.$step);
            $id = substr($digest, 0, 8).'-'.substr($digest, 8, 4).'-5'.substr($digest, 13, 3).'-8'.substr($digest, 17, 3).'-'.substr($digest, 20, 12);
            $operation = LabOperation::query()->firstOrCreate(['id' => $id], [
                'parent_id' => $context->traceId, 'trace_id' => $context->traceId,
                'harness_session' => $context->sessionId, 'provider' => $context->provider,
                'model' => $context->model, 'kind' => 'generation.step',
                ...$this->benchmarkIdentity($context->sessionId),
                'started_at' => now(),
            ]);
        }
        $numbers = [];
        foreach (['prompt_tokens' => 'input_tokens', 'completion_tokens' => 'output_tokens',
            'cache_write_input_tokens' => 'cache_write_tokens', 'cache_read_input_tokens' => 'cache_read_tokens',
            'thought_tokens' => 'reasoning_tokens', 'cost' => 'cost'] as $field => $column) {
            $numbers[$column] = in_array($field, $snapshot['reported_fields'], true) ? $snapshot['usage'][$field] : null;
        }
        $operation->forceFill([
            ...$numbers, 'status' => 'failed', 'duration_ms' => 0,
            'cost_source' => $numbers['cost'] === null ? 'unpriced' : 'provider_reported',
            'metadata' => ['step_index' => $step, 'finish_reason' => 'error', 'failure_usage' => $snapshot],
            'finished_at' => now(),
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
