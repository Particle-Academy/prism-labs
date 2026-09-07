<?php

declare(strict_types=1);

namespace App\Context;

use Illuminate\Support\Facades\DB;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Keeps what leaves the window, in a table.
 *
 * The harness ships no sink but the one that discards, because it will not
 * decide that somebody's conversation is disposable for them. This is the
 * Lab's answer, and it is deliberately the unglamorous one — a table and a
 * `LIKE`, not embeddings.
 *
 * That choice is what makes the probe built on it mean something. If a
 * compacted agent can answer from an evicted turn with nothing but a substring
 * match, then evict → store → recall → answer is sound as a mechanism. Starting
 * with semantic search would have confounded "does the plumbing work" with
 * "does retrieval work", and a failure would not have said which.
 */
final class TableEvictionSink implements EvictionSink
{
    #[\Override]
    public function store(array $messages, string $scope): void
    {
        $rows = [];
        $now = now();

        foreach ($messages as $message) {
            $content = $this->textOf($message);

            if (trim($content) === '') {
                continue;
            }

            $rows[] = [
                'scope' => $scope,
                'role' => $this->roleOf($message),
                'content' => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        // IDEMPOTENT, because the harness cannot be.
        //
        // Compaction is recomputed on every turn from the whole stored thread,
        // so the same early messages are evicted again and again — 224 rows for
        // a ten-turn conversation, all duplicates of about twenty. The harness
        // is right to be stateless about it; remembering what it had already
        // handed over would make eviction depend on a history the thread does
        // not keep.
        //
        // So custody includes not hoarding. Duplicates would also spend the
        // recall budget on the same row repeatedly, which is the failure that
        // would actually be felt: a bounded lookup returning one fact five
        // times instead of five facts.
        $existing = DB::table('evicted_messages')
            ->where('scope', $scope)
            ->pluck('content')
            ->all();

        $fresh = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! in_array($row['content'], $existing, true),
        ));

        if ($fresh === []) {
            return;
        }

        DB::table('evicted_messages')->insert($fresh);
    }

    /**
     * A message as searchable text.
     *
     * Tool results are flattened into their payloads rather than skipped. On an
     * agentic transcript they are the majority of the content — one consumer
     * measured 93% — so a sink that stored only prose would be keeping the part
     * of the conversation least likely to hold the answer.
     */
    private function textOf(Message $message): string
    {
        if ($message instanceof ToolResultMessage) {
            $parts = [];

            foreach ($message->toolResults as $result) {
                $payload = is_array($result->result)
                    ? json_encode($result->result)
                    : (string) $result->result;

                $parts[] = $result->toolName.': '.$payload;
            }

            return implode("\n", $parts);
        }

        if ($message instanceof UserMessage || $message instanceof AssistantMessage) {
            return $message->content;
        }

        return '';
    }

    private function roleOf(Message $message): string
    {
        return match (true) {
            $message instanceof UserMessage => 'user',
            $message instanceof AssistantMessage => 'assistant',
            $message instanceof ToolResultMessage => 'tool',
            default => 'other',
        };
    }
}
