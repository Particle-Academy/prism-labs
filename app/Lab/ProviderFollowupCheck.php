<?php

declare(strict_types=1);

namespace App\Lab;

use App\DocsLab\LiveCostObserver;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\ValueObjects\Usage;
use ReflectionClass;
use Throwable;

/**
 * Asks each provider in the frozen plan whether it accepts a conversation
 * whose earlier tool call carried unreadable arguments and has been answered.
 *
 * Every request is reserved on lab_call_reservations at its worst case before
 * it is sent, through the same observer native runs use. A provider's refusal
 * is the result being measured, so it is recorded and the run moves on. Only
 * fixed, sanitised fields are kept: never a body, a message or a header.
 */
final class ProviderFollowupCheck
{
    private ?int $status = null;

    private ?string $errorType = null;

    private ?string $errorParam = null;

    private bool $connectionFailed = false;

    private bool $sent = false;

    public function __construct(
        private readonly PaidRunContext $context,
        private readonly PaidWorkRegistry $works,
        private readonly ProviderRegistry $providers,
        private readonly ProviderFollowupConversation $conversation,
    ) {}

    /**
     * Run inside an approved context (ApprovedCliRun) only.
     *
     * @return array<string, mixed>
     */
    public function run(LabRunIntent $intent, RunScope $scope): array
    {
        $plan = $intent->payload['followup'] ?? null;
        $question = $scope->payload['input']['question'] ?? null;
        if (! is_array($plan) || $intent->approved_via !== RunAuthority::VIA_FTQ
            || ! is_string($question) || $intent->owner_question_id !== $question) {
            throw new PaidRunNotAuthorised;
        }
        $work = $this->works->bind(ProviderFollowupPlan::WORK_KIND, $intent->id, $plan);

        return $this->context->within($intent->id, $scope, function () use ($intent, $plan, $work): array {
            ProviderFollowupTool::reset();
            Event::listen(RequestSending::class, function (): void {
                $this->sent = true;
            });
            Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
                $this->status = $event->response->status();
                if ($this->status >= 400) {
                    $this->errorType = $this->sanitisedErrorType($event->response->json());
                    $this->errorParam = $this->sanitisedErrorParam($event->response->json());
                }
            });
            Event::listen(ConnectionFailed::class, function (): void {
                $this->connectionFailed = true;
            });

            $results = [];
            $notConfigured = [];
            $stopped = false;
            try {
                // Inside the try: work left unfinished could never be settled.
                $this->context->requireWork(ProviderFollowupPlan::WORK_KIND, $intent->id, $plan);
                foreach ($plan['targets'] as $provider => $target) {
                    if (! $this->providers->isConfigured($provider)) {
                        $notConfigured[] = $provider;

                        continue;
                    }
                    $observer = new NativeCostObserver($intent->id, $intent->authority_session_id, $work->id, $work->payload_digest,
                        $intent->id, $provider, $target['model'],
                        fn (): LabRunIntent => $this->context->authorizedWork(ProviderFollowupPlan::WORK_KIND, $intent->id, $plan));
                    $run = RunContext::root($intent->id, new RunBudget(maxSteps: 1), [], $observer);
                    foreach ($plan['modes'] as $mode) {
                        $callId = bin2hex(random_bytes(16));
                        $worst = $target['worst_case_microusd'] / 1_000_000;
                        try {
                            $observer->reserving(new CallReservation($callId, $provider, $target['model'], $worst, $mode), $run);
                        } catch (CallRefused $refused) {
                            if ($refused->code() !== 'account_exhausted') {
                                throw $refused;
                            }
                            $stopped = true;
                            break 2;
                        }
                        $results[] = $this->request($observer, $run, $callId, $provider, $target, $mode, $plan, $worst);
                    }
                }
            } finally {
                LabPaidWork::query()->whereKey($work->id)->whereNull('finished_at')->update(['finished_at' => now(), 'updated_at' => now()]);
            }

            return [
                'run_id' => $intent->id,
                'core' => ['prism_manager' => $this->sourceOf(PrismManager::class), 'calls_tools' => $this->sourceOf(CallsTools::class)],
                'results' => $results,
                'not_configured' => $notConfigured,
                'configured_outside_plan' => array_values(array_diff(array_column($this->providers->availableForText(), 'key'), array_keys($plan['targets']))),
                'estimated_worst_case_microusd' => $plan['worst_case_microusd'],
                'actual_microusd' => LiveCostObserver::spentFor($intent->id),
                'tool_runs' => ProviderFollowupTool::runs(),
                'stopped_at_cap' => $stopped,
            ];
        }, work: $work);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function request(NativeCostObserver $observer, RunContext $run, string $callId, string $provider, array $target, string $mode, array $plan, float $worst): array
    {
        $this->status = null;
        $this->errorType = null;
        $this->errorParam = null;
        $this->connectionFailed = false;
        $this->sent = false;
        $usage = null;
        $failed = false;
        $ended = $mode === 'text';
        try {
            $pending = Prism::text()->using($provider, $target['model'])
                ->withMessages($this->conversation->messages())
                ->withTools([ProviderFollowupTool::inert()])
                ->withMaxSteps(1)
                ->withMaxTokens($plan['max_output_tokens'])
                ->withClientRetry(0)
                ->withClientOptions(['timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false]);
            if ($mode === 'text') {
                $usage = $pending->asText()->usage;
            } else {
                foreach ($pending->asStream() as $event) {
                    if ($event instanceof StreamEndEvent) {
                        // A cut-off stream still gets an end event, with an error finish.
                        $ended = ! in_array($event->finishReason, [FinishReason::Error, FinishReason::Unknown], true);
                        $usage = $event->usage ?? $usage;
                    }
                }
            }
        } catch (Throwable) {
            // The exception may quote the provider's response, so none of it is kept.
            $failed = true;
        }

        $accepted = ! $failed && $ended && $this->status !== null && $this->status >= 200 && $this->status < 300;
        $notSent = ! $this->sent;
        $unbilled = $notSent || ($this->status !== null && $this->status >= 400 && $this->status < 500);
        [$charged, $known, $outcome] = match (true) {
            $accepted && $usage instanceof Usage => [$this->charge($usage, $target), true, 'completed'],
            $accepted => [$worst, false, 'completed'],
            $unbilled => [0.0, true, 'failed'],
            default => [$worst, false, 'abandoned'],
        };
        $observer->settled(new CallSettlement($callId, $charged, $known, $accepted ? $usage : null, $outcome), $run);

        return [
            'provider' => $provider, 'model' => $target['model'], 'mode' => $mode,
            'status_class' => $this->status === null ? 'none' : intdiv($this->status, 100).'xx',
            'accepted' => $accepted,
            'error_type' => $accepted ? null : ($this->errorType ?? match (true) {
                $notSent => 'request_not_built',
                $this->connectionFailed => 'connection_failed',
                $this->status !== null && $this->status < 300 => 'stream_error',
                default => 'unclassified',
            }),
            'error_param' => $accepted ? null : $this->errorParam,
            'input_tokens' => $accepted ? $usage?->promptTokens : null,
            'output_tokens' => $accepted ? $usage?->completionTokens : null,
            'cost_microusd' => (int) DB::table('lab_call_reservations')->where('call_id', $callId)->value('charged_microusd'),
        ];
    }

    /** @param array<string, mixed> $target */
    private function charge(Usage $usage, array $target): float
    {
        $input = $usage->promptTokens + (int) $usage->cacheReadInputTokens + (int) $usage->cacheWriteInputTokens;

        return ($input * $target['input_microusd_per_mtok'] + $usage->completionTokens * $target['output_microusd_per_mtok']) / 1_000_000_000_000;
    }

    private function sanitisedErrorType(mixed $body): ?string
    {
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        foreach (is_array($error) ? [$error['code'] ?? null, $error['type'] ?? null] : [] as $candidate) {
            if (is_string($candidate) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The request field a provider refused, never its message. A list index
     * such as `input[1].id` is written `input.1.id` so it fits the safe pattern.
     */
    private function sanitisedErrorParam(mixed $body): ?string
    {
        $param = is_array($body) && is_array($body['error'] ?? null) ? ($body['error']['param'] ?? null) : null;
        if (! is_string($param)) {
            return null;
        }
        $param = (string) preg_replace('/\[([0-9]{1,4})\]/', '.$1', $param);

        return preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $param) === 1 ? $param : null;
    }

    /** @param class-string $class */
    private function sourceOf(string $class): string
    {
        return str_replace('\\', '/', (string) (new ReflectionClass($class))->getFileName());
    }
}
