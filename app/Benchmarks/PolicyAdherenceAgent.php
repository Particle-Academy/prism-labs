<?php

declare(strict_types=1);

namespace App\Benchmarks;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * One agent turn, driven through Prism, for an external policy-adherence
 * benchmark to score.
 *
 * WHY THIS EXISTS. A token count says whether context management shrank a
 * transcript. It says nothing about whether the agent still FOLLOWED THE RULES
 * afterwards, which is the only question worth asking of a feature that deletes
 * the agent's own history. Answering that needs a benchmark with a written
 * policy, a simulated user, and a scorer — and the benchmark has to be driving
 * PRISM, or it is measuring somebody else's client.
 *
 * THE TOOLS ARE NEVER EXECUTED HERE, and that is the whole trick. A benchmark
 * of this kind owns the environment: it holds the database, runs the tools and
 * scores the resulting state. So Prism must return the tool CALL and stop.
 *
 * `Tool::requiresApproval()` does exactly that. A tool marked for approval comes
 * back as a pending approval request rather than a result, which is Prism's
 * human-in-the-loop path used here for a machine in the loop. The alternative --
 * giving each tool a stub callback -- would let Prism continue the loop against
 * a fabricated result and quietly corrupt the transcript being scored.
 */
final class PolicyAdherenceAgent
{
    /**
     * @param  array<int, array<string, mixed>>  $tools  Benchmark tool schemas.
     * @param  array<int, array<string, mixed>>  $messages  The conversation so far.
     * @param  array<string, mixed>|null  $contextManagement  Anthropic edits, or null for the control arm.
     * @return array<string, mixed>
     */
    public function turn(
        string $provider,
        string $model,
        string $policy,
        array $tools,
        array $messages,
        ?array $contextManagement = null,
    ): array {
        $pending = Prism::text()
            ->using(Provider::from($provider), $model)
            // ONE step. The benchmark drives the loop, because it owns the
            // environment and has to run each tool against its own database
            // between turns.
            ->withMaxSteps(1)
            // The policy is a SYSTEM PROMPT, not a message. Anthropic refuses a
            // SystemMessage in the messages array, and the distinction matters
            // for what is being measured: `clear_tool_uses` does not touch
            // system content, so if adherence falls it fell while the rule was
            // still in front of the model.
            ->withSystemPrompt($policy)
            ->withMessages($this->hydrate($messages))
            ->withTools($this->toolsFor($tools));

        if ($contextManagement !== null) {
            $pending = $pending->withProviderOptions([
                'anthropic_beta' => 'context-management-2025-06-27',
                'context_management' => $contextManagement,
            ]);
        }

        $response = $pending->asText();

        return [
            'content' => $response->text,
            'tool_calls' => array_map(static fn ($call): array => [
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments(),
            ], $response->toolCalls),
            'finish_reason' => $response->finishReason->value,
            'usage' => [
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
                'thought_tokens' => $response->usage->thoughtTokens,
                'cache_read_input_tokens' => $response->usage->cacheReadInputTokens,
            ],
            // The evidence the whole exercise turns on: what the server said it
            // cleared, alongside the adherence score the benchmark computes.
            // Without both on the same row, a drop in adherence cannot be
            // attributed to clearing rather than to variance.
            'context_management' => $response->additionalContent['context_management'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, UserMessage|AssistantMessage|ToolResultMessage>
     */
    private function hydrate(array $messages): array
    {
        $hydrated = [];
        $pendingResults = [];

        // Consecutive tool results belong in ONE ToolResultMessage. Anthropic
        // requires every result for a single assistant turn to arrive in one
        // message, and emitting one message per result made it see the same
        // tool_result id twice -- reported as
        // "each tool_use must have a single result", five turns after the
        // message that caused it.
        $flush = static function () use (&$hydrated, &$pendingResults): void {
            if ($pendingResults !== []) {
                $hydrated[] = new ToolResultMessage($pendingResults);
                $pendingResults = [];
            }
        };

        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'tool') {
                $pendingResults[] = new ToolResult(
                    toolCallId: (string) ($message['tool_call_id'] ?? ''),
                    toolName: (string) ($message['name'] ?? ''),
                    args: [],
                    result: (string) ($message['content'] ?? ''),
                );

                continue;
            }

            if ($pendingResults !== []) {
                $hydrated[] = new ToolResultMessage($pendingResults);
                $pendingResults = [];
            }

            $hydrated[] = match ($message['role']) {
                'user' => new UserMessage((string) ($message['content'] ?? '')),
                // The tool CALLS have to be rebuilt, not just the text. A
                // tool_result with no matching tool_use in the preceding
                // assistant turn is rejected outright.
                'assistant' => new AssistantMessage(
                    (string) ($message['content'] ?? ''),
                    array_map(
                        static fn (array $call): ToolCall => new ToolCall(
                            id: (string) ($call['id'] ?? ''),
                            name: (string) ($call['name'] ?? ''),
                            arguments: $call['arguments'] ?? [],
                        ),
                        $message['tool_calls'] ?? [],
                    ),
                ),
                default => new UserMessage((string) ($message['content'] ?? '')),
            };
        }

        if ($pendingResults !== []) {
            $hydrated[] = new ToolResultMessage($pendingResults);
        }

        return $hydrated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, \Prism\Prism\Tool>
     */
    private function toolsFor(array $tools): array
    {
        return array_map(function (array $schema): \Prism\Prism\Tool {
            $tool = Tool::as((string) $schema['name'])
                ->for((string) ($schema['description'] ?? ''))
                // Never executed. See the class docblock: the benchmark owns
                // the environment, so a call has to come back uncalled.
                ->requiresApproval()
                ->using(static fn (): string => 'the benchmark executes this, not prism');

            foreach (($schema['parameters']['properties'] ?? []) as $name => $spec) {
                $required = in_array($name, $schema['parameters']['required'] ?? [], true);
                $description = (string) ($spec['description'] ?? $name);

                $tool = match ($spec['type'] ?? 'string') {
                    'number', 'integer' => $tool->withNumberParameter($name, $description, $required),
                    'boolean' => $tool->withBooleanParameter($name, $description, $required),
                    'array' => $tool->withArrayParameter($name, $description, new StringSchema($name, $description), $required),
                    default => $tool->withStringParameter($name, $description, $required),
                };
            }

            return $tool;
        }, $tools);
    }
}
