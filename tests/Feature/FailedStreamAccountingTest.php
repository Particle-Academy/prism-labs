<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LabOperation;
use App\Telemetry\OperationLedger;
use App\Telemetry\PrismTelemetrySubscriber;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Tests\TestCase;

final class FailedStreamAccountingTest extends TestCase
{
    use RefreshDatabase;

    private const ERROR_EVENT = 'Prism\\Prism\\Events\\Telemetry\\GenerationErrored';

    private function context(): TelemetryContext
    {
        return new TelemetryContext((string) Str::uuid(), TelemetryOperation::Stream, 'anthropic', 'offline', microtime(true));
    }

    private function report(Usage $usage, array $fields, int $step = 0): object
    {
        $class = 'Prism\\Prism\\ValueObjects\\StreamFailureUsage';
        if (! class_exists($class)) {
            $this->markTestSkipped('Requires native core 0.146 failure usage.');
        }

        return new $class($usage, $step, $fields);
    }

    public function test_registration_is_safe_with_and_without_the_native_optional_class(): void
    {
        $events = new Dispatcher;
        app(PrismTelemetrySubscriber::class)->subscribe($events);
        $this->assertSame(class_exists(self::ERROR_EVENT), $events->hasListeners(self::ERROR_EVENT));
        app(PrismTelemetrySubscriber::class)->errored(new \stdClass);
        $this->assertSame(0, LabOperation::query()->count());
    }

    public function test_a_failed_attempt_is_counted_once_across_error_and_exception_notifications(): void
    {
        $context = $this->context();
        $report = $this->report(new Usage(11, 3, cost: 0.0, cacheWrite5mInputTokens: 2, cacheWrite1hInputTokens: 3), ['prompt_tokens', 'completion_tokens', 'cost', 'cache_write_5m_input_tokens', 'cache_write_1h_input_tokens']);
        $class = self::ERROR_EVENT;
        $subscriber = app(PrismTelemetrySubscriber::class);
        $subscriber->errored(new $class($context, 4.0, $report));
        $subscriber->errored(new $class($context, 4.0, $report));
        $subscriber->failed(new GenerationFailed($context, 4.0, new RuntimeException('PRIVATE_FAILURE_MESSAGE')));
        $root = LabOperation::query()->findOrFail($context->traceId);
        $child = LabOperation::query()->where('parent_id', $context->traceId)->sole();
        $this->assertSame('failed', $root->status);
        $this->assertNull($root->input_tokens);
        $this->assertNull($root->cost);
        $this->assertSame('failed', $child->status);
        $this->assertEquals(11, $child->input_tokens);
        $this->assertEquals(3, $child->output_tokens);
        $this->assertSame('0.00000000', $child->cost);
        $this->assertSame('provisional', $root->metadata['failure_usage']['status']);
        $this->assertSame(2, $root->metadata['failure_usage']['usage']['cache_write_5m_input_tokens']);
        $this->assertSame(3, $root->metadata['failure_usage']['usage']['cache_write_1h_input_tokens']);
        $this->assertStringNotContainsString('PRIVATE_FAILURE_MESSAGE', json_encode($root->metadata));
        $burn = app(OperationLedger::class)->burn('daily');
        $this->assertSame(11, $burn['input_tokens']);
        $this->assertSame(3, $burn['output_tokens']);
        $this->assertSame(1, $burn['operations']);
        $this->assertSame(100.0, $burn['cost_completeness']);
    }

    public function test_it_reuses_the_failed_step_and_preserves_earlier_completed_step_usage(): void
    {
        $context = $this->context();
        $subscriber = app(PrismTelemetrySubscriber::class);
        $subscriber->step(new StepCompleted($context->withStep(0), FinishReason::ToolCalls, new Usage(7, 2, cost: 0.01)));
        $subscriber->step(new StepCompleted($context->withStep(1), FinishReason::Error, new Usage(11, 0)));
        $existing = LabOperation::query()->where('parent_id', $context->traceId)->where('metadata->step_index', 1)->sole();
        $report = $this->report(new Usage(11, 0), ['prompt_tokens'], 1);
        $class = self::ERROR_EVENT;
        $subscriber->errored(new $class($context, 5.0, $report));
        $subscriber->failed(new GenerationFailed($context, 5.0, new RuntimeException('owned'), $report));
        $this->assertSame(2, LabOperation::query()->where('parent_id', $context->traceId)->count());
        $failed = $existing->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->output_tokens);
        $this->assertNull($failed->cost);
        $earlier = LabOperation::query()->where('parent_id', $context->traceId)->where('metadata->step_index', 0)->sole();
        $this->assertSame('completed', $earlier->status);
        $this->assertEquals(7, $earlier->input_tokens);
        $burn = app(OperationLedger::class)->burn('daily');
        $this->assertSame(18, $burn['input_tokens']);
        $this->assertSame(2, $burn['output_tokens']);
        $this->assertSame(0.01, $burn['priced_cost']);
    }

    public function test_an_unreported_failure_marks_the_root_failed_without_fabricating_numeric_rows(): void
    {
        $context = $this->context();
        app(PrismTelemetrySubscriber::class)->failed(new GenerationFailed($context, 1.0, new RuntimeException('owned')));
        $root = LabOperation::query()->findOrFail($context->traceId);
        $this->assertSame('failed', $root->status);
        $this->assertNull($root->input_tokens);
        $this->assertNull($root->metadata);
        $this->assertSame(0, LabOperation::query()->where('parent_id', $context->traceId)->count());
    }
}
