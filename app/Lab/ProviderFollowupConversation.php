<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * The fixed conversation the check sends: a tool call whose arguments cannot
 * be read, its answer, and a short follow-up. A core that will not replay such
 * a call refuses before any request, which the check reports as not sent.
 */
class ProviderFollowupConversation
{
    public const RAW_ARGUMENTS = '{"query": ';

    /** The tool call's item id. */
    public const CALL_ITEM_ID = 'fc_followup_1';

    /** The id that pairs a call with its result on providers that keep the two apart. */
    public const CALL_RESULT_ID = 'call_followup_1';

    /** @return list<UserMessage|AssistantMessage|ToolResultMessage> */
    public function messages(): array
    {
        return [
            new UserMessage('Look up x.'),
            new AssistantMessage('', [new ToolCall(self::CALL_ITEM_ID, 'lookup', $this->arguments(), self::CALL_RESULT_ID)]),
            new ToolResultMessage([new ToolResult(self::CALL_ITEM_ID, 'lookup', [], 'The arguments could not be read.', self::CALL_RESULT_ID)]),
            new UserMessage('Reply with OK.'),
        ];
    }

    protected function arguments(): string
    {
        return self::RAW_ARGUMENTS;
    }
}
