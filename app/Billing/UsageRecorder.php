<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * The RECORDED side of billing calibration (G-291).
 *
 * Fed from Prism core's telemetry events through the Lab's existing
 * PrismTelemetrySubscriber, because that is the one seam every Prism provider
 * call in this process passes through regardless of which Lab surface made it
 * (chat, benchmarks, consensus, probes, harness sessions). The Lab forces
 * `prism.telemetry.enabled` on, so the events always fire here.
 *
 * One row per generation, keyed by trace id: a doubled failure event (a stream
 * can report GenerationErrored and then throw into GenerationFailed) inserts
 * once. Cost is Harness's OWN computation (CallBudget::costOf) from the
 * configured `prism-harness.pricing`, so it is the number the Lab budgets with;
 * without a trusted rate it is null, never zero.
 */
final class UsageRecorder
{
    /** @var array<string, int> provider requests seen per trace, from StepCompleted */
    private array $steps = [];

    public function step(TelemetryContext $context): void
    {
        $this->steps[$context->traceId] = ($this->steps[$context->traceId] ?? 0) + 1;
    }

    public function completed(TelemetryContext $context, ?Usage $usage): void
    {
        $calls = max(1, $this->steps[$context->traceId] ?? 0);
        unset($this->steps[$context->traceId]);

        // Embeddings and images complete with no usage on the event. Writing
        // zeros would claim a reading nobody took; the call is left unrecorded
        // and shows up as billed-but-not-recorded instead.
        if ($usage === null) {
            return;
        }

        $this->write($context, 'completed', $usage, $calls);
    }

    /**
     * A failed generation still bills what the provider reported for the
     * failing attempt. Only fields the provider actually reported are counted.
     */
    public function failed(TelemetryContext $context, ?object $failureUsage): void
    {
        unset($this->steps[$context->traceId]);

        $class = 'Prism\\Prism\\ValueObjects\\StreamFailureUsage';
        if ($failureUsage === null || ! class_exists($class) || ! $failureUsage instanceof $class) {
            return;
        }
        $data = get_object_vars($failureUsage);
        $usage = $data['usage'] ?? null;
        $fields = $data['reportedFields'] ?? null;
        $step = $data['stepIndex'] ?? 0;
        if (! $usage instanceof Usage || ! is_array($fields)) {
            return;
        }
        $reported = fn (string $field, ?int $value): int => in_array($field, $fields, true) && is_int($value) && $value > 0 ? $value : 0;

        $cacheWrite = $reported('cache_write_input_tokens', $usage->cacheWriteInputTokens)
            ?: $reported('cache_write_5m_input_tokens', $usage->cacheWrite5mInputTokens) + $reported('cache_write_1h_input_tokens', $usage->cacheWrite1hInputTokens);

        $this->write($context, 'failed', new Usage(
            $reported('prompt_tokens', $usage->promptTokens),
            $reported('completion_tokens', $usage->completionTokens),
            $cacheWrite,
            $reported('cache_read_input_tokens', $usage->cacheReadInputTokens),
            cost: in_array('cost', $fields, true) ? $usage->cost : null,
        ), is_int($step) && $step >= 0 ? $step + 1 : 1);
    }

    private function write(TelemetryContext $context, string $outcome, Usage $usage, int $calls): void
    {
        $provider = strtolower($context->provider);
        $now = now();

        try {
            DB::table('provider_usage_records')->insertOrIgnore([
                'trace_id' => $context->traceId,
                'provider' => $provider,
                'model' => $context->model,
                'day' => CarbonImmutable::createFromTimestampUTC((int) floor($context->startedAt))->toDateString(),
                'outcome' => $outcome,
                'input_tokens' => max(0, $usage->promptTokens),
                'cached_input_tokens' => max(0, (int) $usage->cacheReadInputTokens),
                'cache_write_tokens' => max(0, $usage->cacheWriteInputTokens ?? ((int) $usage->cacheWrite5mInputTokens + (int) $usage->cacheWrite1hInputTokens)),
                'output_tokens' => max(0, $usage->completionTokens),
                'harness_cost_usd' => $this->harnessCost($provider, $context->model, $usage),
                'call_count' => $calls,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already recorded for this trace.
        } catch (Throwable $failure) {
            // Recording must never break the provider call it observes (for
            // example before this table is migrated). The missing row then
            // surfaces as billed-but-not-recorded, which is the honest signal.
            Log::warning('G-291: provider usage record not written.', ['failure' => $failure::class]);
        }
    }

    private function harnessCost(string $provider, string $model, Usage $usage): ?float
    {
        $pricing = (array) config('prism-harness.pricing', []);
        if (! is_array($pricing[$provider][$model] ?? null)) {
            return null;
        }

        try {
            // A throwaway run only so Harness will price with its own rules
            // (tiers, cache rates, multiplier). Nothing is reserved or spent.
            $run = RunContext::root('g291-usage-pricing', new RunBudget(1, maxOutputTokensPerCall: 1));
            $cost = CallBudget::forRun($run, $provider, $model, $pricing)->costOf($usage);
        } catch (Throwable) {
            return null;
        }

        return $cost === null || ! is_finite($cost) || $cost < 0 ? null : round($cost, 8);
    }
}
