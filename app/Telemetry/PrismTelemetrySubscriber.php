<?php

declare(strict_types=1);

namespace App\Telemetry;

use App\Billing\UsageRecorder;
use Illuminate\Events\Dispatcher;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Telemetry\TelemetryContext;

final readonly class PrismTelemetrySubscriber
{
    private const ERROR_EVENT = 'Prism\\Prism\\Events\\Telemetry\\GenerationErrored';

    /**
     * The usage recorder (G-291) rides the same events: this subscriber is the
     * one place every Prism provider call in the Lab process is observed.
     */
    public function __construct(private OperationLedger $ledger, private UsageRecorder $usage) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(GenerationStarted::class, $this->started(...));
        $events->listen(GenerationCompleted::class, $this->completed(...));
        $events->listen(GenerationFailed::class, $this->failed(...));
        if (class_exists(self::ERROR_EVENT)) {
            $events->listen(self::ERROR_EVENT, $this->errored(...));
        }
        $events->listen(StepCompleted::class, $this->step(...));
        $events->listen(ToolInvoked::class, $this->tool(...));
    }

    public function started(GenerationStarted $event): void
    {
        $this->ledger->startGeneration($event->context);
    }

    public function completed(GenerationCompleted $event): void
    {
        $this->ledger->completeGeneration($event->context, $event->durationMs, $event->usage, $event->finishReason?->value);
        $this->usage->completed($event->context, $event->usage);
    }

    public function failed(GenerationFailed $event): void
    {
        $failureUsage = get_object_vars($event)['failureUsage'] ?? null;
        $this->ledger->failGeneration($event->context, $event->durationMs, $event->exception, is_object($failureUsage) ? $failureUsage : null);
        $this->usage->failed($event->context, is_object($failureUsage) ? $failureUsage : null);
    }

    public function errored(object $event): void
    {
        $class = self::ERROR_EVENT;
        if (! class_exists($class) || ! $event instanceof $class) {
            return;
        }
        $data = get_object_vars($event);
        $context = $data['context'] ?? null;
        $duration = $data['durationMs'] ?? null;
        if ($context instanceof TelemetryContext && is_float($duration)) {
            $failureUsage = $data['failureUsage'] ?? null;
            $this->ledger->errorGeneration($context, $duration, is_object($failureUsage) ? $failureUsage : null);
            $this->usage->failed($context, is_object($failureUsage) ? $failureUsage : null);
        }
    }

    public function step(StepCompleted $event): void
    {
        $this->ledger->recordChild($event->context, 'generation.step', 0, [
            'step_index' => $event->context->stepIndex,
            'finish_reason' => $event->finishReason?->value,
        ], $event->usage);
        $this->usage->step($event->context);
    }

    public function tool(ToolInvoked $event): void
    {
        $this->ledger->recordChild($event->context, 'tool.call', $event->durationMs, [
            'tool_index' => $event->context->toolIndex,
            'tool_name' => $event->toolName,
            'tool_call_id' => $event->toolCallId,
        ]);
    }
}
