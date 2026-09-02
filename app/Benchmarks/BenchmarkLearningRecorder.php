<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Learnings\LearningStore;
use App\Learnings\Severity;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use Illuminate\Support\Collection;
use Throwable;

/**
 * EVERY benchmark run leaves a 0L behind. No exceptions, including the ones
 * that produced nothing.
 *
 * A run has three terminal shapes and each of them used to end differently:
 * `completed` wrote receipts, `failed` left its reasons only in per-lane rows,
 * and `cancelled` — the emergency stop — left nothing at all. The last two are
 * the ones worth writing down. The expensive part of a benchmark is deciding
 * what to measure and standing the lanes up, so a run that dies at minute four
 * has already spent most of what it was going to spend; throwing away the
 * reason is what makes it wasted rather than merely unsuccessful.
 *
 * One place rather than three, because "file a learning" kept being the step
 * that each terminal path forgot in its own way.
 */
final readonly class BenchmarkLearningRecorder
{
    public function __construct(private LearningStore $learnings) {}

    /**
     * Never throws. The run's status is the record of what happened; a 0L that
     * could not be written must not take that down with it. A missing learning
     * is a gap, a lost run status is corruption.
     */
    public function record(BenchmarkRun $run): void
    {
        if (is_string($run->learning_ref) && $run->learning_ref !== '') {
            // Reconcile fires once per finishing lane and the fuse can be
            // pressed twice. One run, one 0L.
            return;
        }

        try {
            $lanes = BenchmarkLane::query()->where('benchmark_run_id', $run->id)->orderBy('ordinal')->get();
            $learning = $this->learnings->file(
                title: $this->title($run, $lanes),
                filedBy: 'prism-lab/benchmark',
                languages: $lanes->pluck('language')->unique()->values()->all(),
                whatWasLearned: $this->whatWasLearned($run, $lanes),
                evidence: $this->evidence($lanes),
                whyItMatters: $this->whyItMatters($run, $lanes),
                whatShouldChange: $this->whatShouldChange($run, $lanes),
                severity: $this->severity($run, $lanes),
            );

            $run->forceFill(['learning_ref' => $learning->ref])->save();
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /** @param Collection<int, BenchmarkLane> $lanes */
    private function title(BenchmarkRun $run, Collection $lanes): string
    {
        $name = $run->spec?->name ?? 'Benchmark run';
        $completed = $lanes->where('status', 'completed')->count();

        return match (true) {
            $run->status === 'cancelled' => sprintf('%s — stopped by the operator after %d of %d lanes finished', $name, $completed, $lanes->count()),
            $completed === 0 && $lanes->isNotEmpty() => sprintf('%s — every lane failed', $name),
            default => sprintf('%s — %d of %d lanes completed', $name, $completed, $lanes->count()),
        };
    }

    /** @param Collection<int, BenchmarkLane> $lanes */
    private function whatWasLearned(BenchmarkRun $run, Collection $lanes): string
    {
        $matrix = $lanes->map(fn (BenchmarkLane $lane): string => sprintf(
            '- `%s` / `%s` / `%s` — **%s**%s',
            $lane->language, $lane->provider, $lane->model, $lane->status,
            $lane->score === null ? '' : sprintf(' (score %s)', $lane->score),
        ))->implode("\n");

        $headline = match (true) {
            $run->status === 'cancelled' => 'A human stopped this run before it finished, so it holds no verdict on the languages it compares. What it does hold is how far each lane got before the stop, which is the part that is expensive to reproduce.',
            $lanes->where('status', 'completed')->isEmpty() => 'No lane produced a working artifact, so the benchmark answered nothing about the languages it was comparing. What it did establish is where the run stops.',
            default => 'The lanes that completed are comparable to each other. The ones that did not are not evidence about their language and must not be read as such.',
        };

        return $headline."\n\nThe matrix as it ran:\n\n".$matrix;
    }

    /**
     * The sentence that says why a lane stopped.
     *
     * Preferred from the recorded ACTIVITY, because that is where the useful
     * text is: an exception path stores only `failure_class` in the proof
     * (`PrismException` — true and useless), while the activity carries
     * "Anthropic Error [404]: not_found_error - model: ...". Falls back to the
     * proof for lanes that failed without raising.
     */
    private function failureText(BenchmarkLane $lane): ?string
    {
        $summary = $lane->activities()->where('level', 'error')->orderByDesc('id')->value('summary');

        if (is_string($summary) && trim($summary) !== '') {
            return $summary;
        }

        $reason = is_array($lane->proof) ? ($lane->proof['reason'] ?? $lane->proof['failure_class'] ?? null) : null;

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }

    /** @param Collection<int, BenchmarkLane> $lanes */
    private function evidence(Collection $lanes): string
    {
        $lines = $lanes->map(function (BenchmarkLane $lane): string {
            $reason = $this->failureText($lane);
            $events = $lane->activities()->count();

            return sprintf(
                '`lane-%d` %s/%s: %s — %d recorded event(s)%s',
                $lane->ordinal, $lane->language, $lane->model, $lane->status, $events,
                is_string($reason) && $reason !== '' ? ' — '.mb_substr($reason, 0, 400) : '',
            );
        })->implode("\n");

        return $lines === '' ? 'The run finished with no lanes recorded.' : $lines;
    }

    /** @param Collection<int, BenchmarkLane> $lanes */
    private function whyItMatters(BenchmarkRun $run, Collection $lanes): string
    {
        if ($run->status === 'cancelled') {
            return 'A stopped run looks like nothing happened, and by default it leaves nothing behind — but the spend and the elapsed time are already gone. Recording where it got to is what stops the next operator paying for the same four minutes to see the same thing.';
        }

        return $lanes->where('status', 'completed')->isEmpty()
            ? 'A run where every lane failed is the one most worth recording. The cost of a benchmark is in choosing what to measure and standing the lanes up, so an unrecorded total failure means the next person pays that cost again to reach the same dead end.'
            : 'Recorded so the comparison can be read later without re-running it, and so a lane that failed here is not quietly forgotten when the others are cited.';
    }

    /** @param Collection<int, BenchmarkLane> $lanes */
    private function whatShouldChange(BenchmarkRun $run, Collection $lanes): ?string
    {
        $broken = $lanes->whereIn('status', ['failed', 'cancelled']);

        if ($broken->isEmpty()) {
            return null;
        }

        // A model id the provider rejects is the one failure worth naming
        // outright: it is a configuration fault wearing a result's clothes, and
        // every lane carrying that id keeps failing until the spec changes.
        //
        // Read from the ACTIVITY, not from `proof`. An exception path records
        // only `failure_class` in the proof — `PrismException`, which names
        // nothing — while the sentence that identifies the cause
        // ("Anthropic Error [404]: not_found_error - model: ...") is the
        // activity summary. Checking the proof alone missed this on the very
        // run that motivated the check.
        $notFound = $broken->filter(fn (BenchmarkLane $lane): bool => $this->failureText($lane) !== null
            && (str_contains($this->failureText($lane), 'not_found') || str_contains($this->failureText($lane), '404')));

        if ($notFound->isNotEmpty()) {
            return sprintf(
                'The provider rejected these model ids as unknown: %s. That is a spec problem rather than a lane result — and the spec is frozen and digested, so it needs a NEW REVISION naming a current model (`php artisan benchmark:respec`), never an edit in place.',
                $notFound->pluck('model')->unique()->implode(', '),
            );
        }

        return $run->status === 'cancelled'
            ? 'If the stop was because the run was going nowhere, the per-lane events above say where it stalled; re-running without reading them costs the same again.'
            : 'Resolve the per-lane reasons above before citing this comparison as covering those lanes.';
    }

    /** @param Collection<int, BenchmarkLane> $lanes */
    private function severity(BenchmarkRun $run, Collection $lanes): Severity
    {
        return match (true) {
            $run->status === 'cancelled' => Severity::Notable,
            $lanes->where('status', 'completed')->isEmpty() && $lanes->isNotEmpty() => Severity::Urgent,
            $lanes->where('status', 'failed')->isNotEmpty() => Severity::Notable,
            default => Severity::Info,
        };
    }
}
