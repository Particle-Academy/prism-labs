<?php

declare(strict_types=1);

namespace App\Telemetry;

use Illuminate\Events\Dispatcher;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;

final readonly class PrismTelemetrySubscriber
{
    public function __construct(private OperationLedger $ledger) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(GenerationStarted::class, $this->started(...));
        $events->listen(GenerationCompleted::class, $this->completed(...));
        $events->listen(GenerationFailed::class, $this->failed(...));
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
    }

    public function failed(GenerationFailed $event): void
    {
        $this->ledger->failGeneration($event->context, $event->durationMs, $event->exception);
    }

    public function step(StepCompleted $event): void
    {
        $this->ledger->recordChild($event->context, 'generation.step', 0, [
            'step_index' => $event->context->stepIndex,
            'finish_reason' => $event->finishReason?->value,
        ], $event->usage);
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
