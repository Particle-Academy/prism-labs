<?php

declare(strict_types=1);

namespace App\Learnings;

use Illuminate\Support\Collection;

/**
 * The learnings still waiting on somebody.
 *
 * The Lab files a 0L for every terminal run and then, until now, forgot about
 * it. Nothing asked what was still outstanding, so the backlog and the archive
 * were the same list — and a finding nobody is accountable for is a finding
 * that will be rediscovered by the next run instead of fixed.
 */
final class LearningBacklog
{
    /**
     * Open, worst first.
     *
     * Ordered by severity rather than by date on purpose: an urgent learning
     * from last week outranks an informational one from this morning, and a
     * backlog sorted newest-first buries exactly the items it exists to raise.
     *
     * @return Collection<int, Learning>
     */
    public function open(?Severity $atLeast = null): Collection
    {
        $rank = [Severity::Urgent->value => 0, Severity::Notable->value => 1, Severity::Info->value => 2];
        $floor = $atLeast === null ? 2 : ($rank[$atLeast->value] ?? 2);

        return Learning::query()
            ->whereNull('acted_at')
            ->get()
            ->filter(fn (Learning $l): bool => ($rank[$l->severity->value] ?? 2) <= $floor)
            ->sortBy([
                fn (Learning $a, Learning $b): int => ($rank[$a->severity->value] ?? 2) <=> ($rank[$b->severity->value] ?? 2),
                fn (Learning $a, Learning $b): int => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * Mark one dealt with, with a note saying what was done.
     *
     * The note is REQUIRED. "Closed" without a reason is the same as deleted —
     * it destroys the one thing a reader of this backlog needs later, which is
     * whether the finding was fixed, deferred deliberately, or judged wrong.
     */
    public function close(string $ref, string $note): ?Learning
    {
        $learning = Learning::query()->where('ref', $ref)->first();

        if (! $learning instanceof Learning || trim($note) === '') {
            return null;
        }

        $learning->forceFill(['acted_at' => now(), 'acted_note' => trim($note)])->save();

        return $learning;
    }

    /**
     * Record that these were handed to an agent.
     *
     * Separate from closing, because being passed on is not being dealt with.
     *
     * @param  Collection<int, Learning>  $learnings
     */
    public function markSent(Collection $learnings): void
    {
        Learning::query()->whereIn('id', $learnings->pluck('id'))->update(['sent_at' => now()]);
    }
}
