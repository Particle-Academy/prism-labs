<?php

declare(strict_types=1);

namespace App\Context;

use App\Benchmarks\SummaryLossProbe;
use Prism\Harness\Context\CompactionOutcome;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Keeps every compaction a run performed, so a probe can read the window the
 * model was actually given.
 *
 * ## Why a probe needs this at all
 *
 * {@see SummaryLossProbe} claims that a piece of detail was
 * DROPPED by a summariser. Nothing in the harness reports that — a strategy
 * hands back what it kept and the thread sends it, and by the time a response
 * comes back the window that produced it is gone. Without a record, "the
 * summary lost it" would be inferred from the model failing to answer, which is
 * exactly the inference a probe is supposed to replace with evidence: a model
 * can also fail to answer a question whose answer was sitting in front of it.
 *
 * So this stores the kept half of every compaction and lets the probe assert on
 * the text. That assertion is the vacuity guard — see
 * `.ai/knowledge/benchmark-vacuity-guard.md`.
 *
 * ## Every window, and the last one separately
 *
 * A summariser REWRITES its own summary on each turn, so the same detail can be
 * carried on one turn and dropped on the next. Those are two different
 * questions and this class serves both:
 *
 *  - {@see finalKept()} / the last of {@see windows()} is the window that built
 *    the prompt the model ANSWERED from. That is the guard — whether the detail
 *    was available when it mattered.
 *  - all of {@see windows()} is the measurement — how many rewrites a detail
 *    lasted before it went.
 *
 * Do not use the second as the first. Compaction begins a few turns in, when an
 * early planted turn is most of what there is to summarise, so the opening
 * summaries almost always carry it; a probe that required absence from EVERY
 * window would report "the summariser kept it" about a run where it was gone
 * long before the question. That is exactly the direction a guard must not
 * fail in, and this one did until a live run showed it.
 *
 * ## It decides nothing
 *
 * A recorder that changed the outcome would be a strategy, and the probe would
 * be measuring the recorder. It delegates and returns the inner result
 * untouched.
 */
final class RecordingCompaction implements CompactionStrategy
{
    /** @var list<CompactionOutcome> */
    private array $outcomes = [];

    public function __construct(private readonly CompactionStrategy $inner) {}

    #[\Override]
    public function compact(array $messages): CompactionOutcome
    {
        $outcome = $this->inner->compact($messages);

        $this->outcomes[] = $outcome;

        return $outcome;
    }

    /**
     * How many times something was actually evicted.
     *
     * Counts compactions that REMOVED something, not calls. A short thread
     * returns `untouched()` on every turn, and a probe that read this as
     * "compaction ran nine times" would be reporting activity where there was
     * none.
     */
    public function compactions(): int
    {
        return count(array_filter($this->outcomes, fn (CompactionOutcome $o): bool => $o->compacted()));
    }

    /**
     * Every compaction that actually removed something, in order.
     *
     * Untouched outcomes are excluded deliberately: before anything is evicted
     * the window is the whole conversation, so of course it contains the
     * planted detail, and including those would make every run look as though
     * the summary had kept it.
     *
     * @return list<CompactionOutcome>
     */
    public function compactedOutcomes(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            fn (CompactionOutcome $o): bool => $o->compacted(),
        ));
    }

    /**
     * The windows the model was given, one string per compaction that removed
     * something.
     *
     * @return list<string>
     */
    public function windows(): array
    {
        return array_map(
            fn (CompactionOutcome $o): string => implode("\n", array_map($this->textOf(...), $o->kept)),
            $this->compactedOutcomes(),
        );
    }

    /**
     * The kept messages of the last compaction that removed something — the
     * window that built the final prompt.
     *
     * Handed back as messages rather than text so a caller can pick out one of
     * them, which is what a probe reporting on a summariser needs: the
     * summary itself, quotable, rather than a haystack it asserts things about.
     *
     * @return list<Message>
     */
    public function finalKept(): array
    {
        $compacted = $this->compactedOutcomes();

        return $compacted === [] ? [] : $compacted[count($compacted) - 1]->kept;
    }

    /**
     * A message as text, for INSPECTION rather than storage.
     *
     * Deliberately not {@see TableEvictionSink}'s version, which drops empty
     * content and shapes rows for a table. This one keeps everything the model
     * would have read, including the summariser's own marker message — the
     * single string a probe most needs to search.
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
}
