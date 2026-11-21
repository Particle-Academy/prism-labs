<?php

declare(strict_types=1);

namespace App\DocsLab;

use Prism\AgentDocs\DocsResult;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * What one lane did with one scenario: the agent's result, the store it wrote
 * to, and what the conversation actually contained.
 *
 * The transcript is read back from the HARNESS THREAD the agent ran on, not
 * from the lane's own bookkeeping, so the scripted and live lanes are graded
 * from the same record of what the model was shown and which tool calls it
 * made, and a lane cannot report a call that never reached the agent.
 */
final readonly class LaneRun
{
    /**
     * @param  list<array{tool: string, ok: ?bool, problems: list<string>}>  $calls
     */
    public function __construct(
        public ?DocsResult $result,
        public ?string $saved,
        public ?string $storeDraft,
        public string $shown = '',
        public array $calls = [],
        public int $modelCalls = 0,
        public ?string $error = null,
        public bool $stopRun = false,
    ) {}

    /** @param iterable<mixed> $messages */
    public static function transcript(iterable $messages): array
    {
        $shown = null;
        $calls = [];
        $models = 0;
        $byId = [];
        foreach ($messages as $message) {
            if ($message instanceof UserMessage && $shown === null) {
                $shown = (string) $message->content;
            }
            if ($message instanceof AssistantMessage) {
                $models++;
                foreach ($message->toolCalls as $call) {
                    $byId[$call->id] = count($calls);
                    $calls[] = ['tool' => $call->name, 'ok' => null, 'problems' => []];
                }
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $index = $byId[$result->toolCallId] ?? null;
                    if ($index === null) {
                        continue;
                    }
                    $decoded = is_string($result->result) ? json_decode($result->result, true) : $result->result;
                    $calls[$index]['ok'] = is_array($decoded) && is_bool($decoded['ok'] ?? null) ? $decoded['ok'] : null;
                    foreach (is_array($decoded) && is_array($decoded['problems'] ?? null) ? $decoded['problems'] : [] as $problem) {
                        if (is_array($problem) && is_string($problem['code'] ?? null)) {
                            $calls[$index]['problems'][] = $problem['code'];
                        }
                    }
                }
            }
        }

        return ['shown' => $shown ?? '', 'calls' => $calls, 'model_calls' => $models];
    }
}
