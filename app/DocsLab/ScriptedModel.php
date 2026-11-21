<?php

declare(strict_types=1);

namespace App\DocsLab;

use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\PrismManager;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

/**
 * Prism's fake provider, taught to RUN the tool calls it is scripted with.
 *
 * Prism::fake() returns canned responses and never executes a tool, so it
 * cannot exercise an agent whose behaviour lives in its tools. This is the same
 * fake, installed the way Prism::fake() installs itself, with one addition: a
 * scripted turn that names tool calls has them executed against the tools the
 * request actually offered, through Prism's own CallsTools, exactly as a real
 * provider handler does. So the tools a scripted lane reaches are the ones the
 * harness resolved for the agent's session, and the .docx they write is real.
 *
 * Each text() call plays turns until a closing string or the request's step
 * limit, so it serves both the harness's ordinary loop (one call, many steps)
 * and its cost-capped loop (one step per call, each admitted and priced).
 *
 * `structured()` answers from a separate queue, for the judge in tests.
 *
 * No network and no model: every "decision" is in the script.
 */
final class ScriptedModel extends PrismFake
{
    use CallsTools;

    private static int $calls = 0;

    /** @var list<TextRequest|StructuredRequest> */
    private array $requests = [];

    /**
     * @param  list<list<ToolCall>|string>  $script  each turn: the tool calls the model makes, or its closing text
     * @param  list<array<string, mixed>>  $structured  answers to structured requests, in order
     */
    public function __construct(private array $script, private array $structured = [], private readonly Usage $usage = new Usage(1, 1))
    {
        parent::__construct([]);
    }

    /**
     * Install as the provider for every Prism call in this process.
     *
     * @param  list<list<ToolCall>|string>  $script
     * @param  list<array<string, mixed>>  $structured
     */
    public static function install(array $script, array $structured = [], Usage $usage = new Usage(1, 1)): self
    {
        $fake = new self($script, $structured, $usage);
        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(private readonly ScriptedModel $fake) {}

            public function resolve(mixed $name, array $providerConfig = []): ScriptedModel
            {
                $this->fake->setProviderConfig($providerConfig);

                return $this->fake;
            }
        });

        return $fake;
    }

    /**
     * A scenario's `scripted` turns as Prism tool calls.
     *
     * @param  list<list<array{tool: string, arguments: array<string, mixed>}>|string>  $turns
     * @return list<list<ToolCall>|string>
     */
    public static function fromScenario(array $turns): array
    {
        return array_map(static fn (array|string $turn): array|string => is_string($turn) ? $turn : array_map(
            static fn (array $call): ToolCall => new ToolCall('call_lab_'.(++self::$calls), (string) $call['tool'], (array) ($call['arguments'] ?? [])),
            $turn,
        ), $turns);
    }

    public function text(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;
        $steps = [];
        $toolCalls = [];
        $toolResults = [];
        $history = $request->messages();
        $added = [];

        for ($i = 0; $i < max(1, $request->maxSteps()); $i++) {
            $turn = array_shift($this->script) ?? '';
            if (is_string($turn)) {
                $steps[] = $this->step($turn, FinishReason::Stop, [], [], [...$history, ...$added], $request);
                $added[] = new AssistantMessage($turn);

                return $this->response($steps, $turn, FinishReason::Stop, $toolCalls, $toolResults, [...$history, ...$added]);
            }

            $results = $this->callTools($request->tools(), $turn);
            $steps[] = $this->step('', FinishReason::ToolCalls, $turn, $results, [...$history, ...$added], $request);
            $added[] = new AssistantMessage('', $turn);
            $added[] = new ToolResultMessage($results);
            array_push($toolCalls, ...$turn);
            array_push($toolResults, ...$results);
        }

        return $this->response($steps, '', FinishReason::ToolCalls, $toolCalls, $toolResults, [...$history, ...$added]);
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->requests[] = $request;
        $answer = array_shift($this->structured);
        if (! is_array($answer)) {
            throw new RuntimeException('The structured script ran out.');
        }
        $text = (string) json_encode($answer);
        $step = new StructuredStep(
            text: $text,
            finishReason: FinishReason::Stop,
            usage: $this->usage,
            meta: new Meta('scripted', 'scripted'),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            structured: $answer,
        );

        return new StructuredResponse(
            steps: collect([$step]),
            text: $text,
            structured: $answer,
            finishReason: FinishReason::Stop,
            usage: $this->usage,
            meta: new Meta('scripted', 'scripted'),
        );
    }

    /** @return list<TextRequest|StructuredRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function remaining(): int
    {
        return count($this->script);
    }

    /**
     * @param  list<ToolCall>  $calls
     * @param  list<ToolResult>  $results
     * @param  list<mixed>  $messages  what this step was sent, as a real handler records it
     */
    private function step(string $text, FinishReason $reason, array $calls, array $results, array $messages, TextRequest $request): Step
    {
        return new Step(
            text: $text,
            finishReason: $reason,
            toolCalls: $calls,
            toolResults: $results,
            providerToolCalls: [],
            usage: $this->usage,
            meta: new Meta('scripted', 'scripted'),
            messages: $messages,
            systemPrompts: $request->systemPrompts(),
        );
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     * @param  list<mixed>  $messages
     */
    private function response(array $steps, string $text, FinishReason $reason, array $toolCalls, array $toolResults, array $messages): TextResponse
    {
        if ($steps === []) {
            throw new RuntimeException('The script ran out.');
        }

        return new TextResponse(
            steps: collect($steps),
            text: $text,
            finishReason: $reason,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: new Usage($this->usage->promptTokens * count($steps), $this->usage->completionTokens * count($steps)),
            meta: new Meta('scripted', 'scripted'),
            messages: collect($messages),
        );
    }
}
