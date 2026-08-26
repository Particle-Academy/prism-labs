<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use Inertia\Inertia;
use Inertia\Response;
use Prism\Harness\Models\Thread;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * What the harness actually stored.
 *
 * The harness had no visible surface at all — threads and session state existed
 * only as rows, so "the conversation persisted" was a claim you took on trust.
 * This page rebuilds each stored message through the harness's own mapper and
 * shows what came back, which is the part worth seeing: not the JSON on disk
 * but the value objects a provider would actually receive.
 *
 * That distinction is not cosmetic. The defect that shipped in v0.1.1 stored
 * correctly and loaded correctly and produced an array where a value object
 * belonged — visible here, invisible in a row dump.
 */
final class ThreadController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Lab/Threads', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'threads' => Thread::query()
                ->withCount('storedMessages')
                ->latest('updated_at')
                ->limit(25)
                ->get()
                ->map(fn (Thread $thread): array => $this->present($thread))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Thread $thread): array
    {
        return [
            'id' => $thread->id,
            'scope' => $thread->scope,
            'participant' => $thread->participant_type === null
                ? null
                : class_basename((string) $thread->participant_type).' #'.$thread->participant_id,
            'message_count' => $thread->stored_messages_count,
            'updated_at' => $thread->updated_at?->diffForHumans(),
            'messages' => $this->messages($thread),
        ];
    }

    /**
     * Rebuilt through the harness, not read raw.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(Thread $thread): array
    {
        $rebuilt = [];

        foreach ($thread->messages() as $message) {
            $rebuilt[] = [
                'role' => $this->role($message),
                // The concrete class, because that is the thing that was wrong
                // when the harness was silently flattening value objects.
                'class' => class_basename($message),
                'text' => $this->text($message),
                'tool_calls' => $message instanceof AssistantMessage
                    ? array_map(fn ($call): array => ['name' => $call->name, 'arguments' => $call->arguments], $message->toolCalls)
                    : [],
                'tool_results' => $message instanceof ToolResultMessage
                    ? array_map(fn ($result): array => ['name' => $result->toolName, 'result' => $result->result], $message->toolResults)
                    : [],
                // Assistant messages carry provider value objects here —
                // citations from Anthropic, thinking blocks. Showing the
                // rebuilt class names proves they survived storage.
                'additional' => $message instanceof AssistantMessage
                    ? $this->describeAdditional($message->additionalContent)
                    : [],
            ];
        }

        return $rebuilt;
    }

    private function role(object $message): string
    {
        return match (true) {
            $message instanceof SystemMessage => 'system',
            $message instanceof UserMessage => 'user',
            $message instanceof AssistantMessage => 'assistant',
            $message instanceof ToolResultMessage => 'tool',
            default => 'unknown',
        };
    }

    private function text(object $message): string
    {
        return match (true) {
            $message instanceof UserMessage => $message->text(),
            $message instanceof AssistantMessage, $message instanceof SystemMessage => $message->content,
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $additional
     * @return array<string, string>
     */
    private function describeAdditional(array $additional): array
    {
        $described = [];

        foreach ($additional as $key => $value) {
            $described[(string) $key] = match (true) {
                is_object($value) => class_basename($value),
                is_array($value) && $value !== [] && is_object($value[array_key_first($value)]) => class_basename($value[array_key_first($value)]).'[]',
                is_array($value) => 'array('.count($value).')',
                is_scalar($value) => gettype($value),
                default => 'null',
            };
        }

        return $described;
    }
}
