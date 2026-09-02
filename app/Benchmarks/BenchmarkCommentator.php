<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Lab\ModelCatalogue;
use App\Models\BenchmarkCommentary;
use App\Models\BenchmarkLaneActivity;
use App\Models\BenchmarkRun;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The PLab overseer, calling a run while it happens.
 *
 * Runs on its OWN queue, never in a page request and never on `default`. Both
 * of those are deliberate and both were learned the hard way: a model call
 * inside a page request stalls the Lab's single FastCGI worker until Caddy
 * gives up on it, and `default` is occupied by the lane itself for the whole
 * length of the run — commentary queued behind it would arrive after the thing
 * it was meant to narrate had finished.
 *
 * Never throws. Commentary is decoration on a run; a run that completes with a
 * silent ticker is a worse experience, and a run that FAILS because its
 * narrator did is an absurdity.
 */
final readonly class BenchmarkCommentator
{
    /** Enough events to say something specific, few enough to stay current. */
    private const BATCH = 25;

    /** How much of the previous call to show, so it does not repeat itself. */
    private const RECENT_LINES = 3;

    public function __construct(private LabSession $sessions, private ModelCatalogue $catalogue) {}

    public function call(BenchmarkRun $run): ?BenchmarkCommentary
    {
        try {
            return $this->generate($run);
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }
    }

    private function generate(BenchmarkRun $run): ?BenchmarkCommentary
    {
        $since = (int) BenchmarkCommentary::query()->where('benchmark_run_id', $run->id)->max('after_activity_id');
        $laneIds = $run->lanes()->pluck('id');

        $events = BenchmarkLaneActivity::query()
            ->whereIn('benchmark_lane_id', $laneIds)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $model = $this->catalogue->cheapestConfigured();

        if ($model === null) {
            return null;
        }

        $response = $this->sessions->resolveScope('commentary:'.$run->id)
            ->usingMode('commentary')
            ->usingProvider($model['provider'])
            ->usingModel($model['model'])
            ->send($this->prompt($run, $events));

        $line = trim($response->text());

        if ($line === '') {
            return null;
        }

        return BenchmarkCommentary::query()->create([
            'benchmark_run_id' => $run->id,
            // Bounded: a ticker line that scrolls for a minute is not a ticker
            // line, and a model that ignores "one or two short lines" must not
            // be able to fill the screen.
            'line' => mb_substr($line, 0, 400),
            'after_activity_id' => (int) $events->max('id'),
        ]);
    }

    /** @param Collection<int, BenchmarkLaneActivity> $events */
    private function prompt(BenchmarkRun $run, Collection $events): string
    {
        $lanes = $run->lanes->mapWithKeys(fn ($lane): array => [
            $lane->id => sprintf('Lane %d (%s %s)', $lane->ordinal, $lane->provider, $lane->model),
        ]);

        $feed = $events->map(fn (BenchmarkLaneActivity $event): string => sprintf(
            '- %s %s: %s',
            $lanes[$event->benchmark_lane_id] ?? 'Lane ?',
            $event->kind,
            mb_substr((string) $event->summary, 0, 160),
        ))->implode("\n");

        $recent = BenchmarkCommentary::query()
            ->where('benchmark_run_id', $run->id)
            ->latest('id')->limit(self::RECENT_LINES)->pluck('line')->reverse()
            ->implode("\n");

        $standings = $run->lanes->map(fn ($lane): string => sprintf(
            '- Lane %d (%s %s) is %s', $lane->ordinal, $lane->provider, $lane->model, $lane->status,
        ))->implode("\n");

        return implode("\n\n", array_filter([
            sprintf('Benchmark: %s (revision %d).', $run->spec?->name ?? 'unnamed', $run->spec?->revision ?? 0),
            "Lanes right now:\n".$standings,
            $recent === '' ? null : "You said, most recently:\n".$recent,
            "Just in:\n".$feed,
            'Call it. One or two short lines, nothing else.',
        ]));
    }
}
