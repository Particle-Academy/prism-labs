<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Lab\ModelCatalogue;
use App\Models\BenchmarkCommentary;
use App\Models\BenchmarkLaneActivity;
use App\Models\BenchmarkRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $laneIds = $run->lanes()->pluck('id');
        $marks = BenchmarkCommentary::query()->where('benchmark_run_id', $run->id);
        $sinceActivity = (int) (clone $marks)->max('after_activity_id');
        $sinceOperation = (clone $marks)->max('after_operation_at');

        $activities = BenchmarkLaneActivity::query()
            ->whereIn('benchmark_lane_id', $laneIds)
            ->where('id', '>', $sinceActivity)
            ->orderBy('id')->limit(self::BATCH)->get();

        // BOTH stores. `benchmark_lane_activities` carries the narrated
        // milestones and `lab_operations` carries the tool calls, and an agent
        // deep in a build emits the second for minutes without emitting the
        // first. Reading activities alone left the ticker silent through
        // exactly the stretch worth calling — the same mistake the lane
        // heartbeat made before it was taught to read both.
        $operations = DB::table('lab_operations')
            ->whereIn('benchmark_lane_id', $laneIds)
            ->when(is_string($sinceOperation), fn ($query) => $query->where('started_at', '>', $sinceOperation))
            ->orderBy('started_at')->limit(self::BATCH)->get();

        if ($activities->isEmpty() && $operations->isEmpty()) {
            return null;
        }

        $events = $this->feed($run, $activities, $operations);

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
            'after_activity_id' => $activities->isEmpty() ? $sinceActivity : (int) $activities->max('id'),
            'after_operation_at' => $operations->isEmpty() ? $sinceOperation : $operations->max('started_at'),
        ]);
    }

    /**
     * One feed in time order, from both stores.
     *
     * A tool call says WHAT the agent did and an activity says what the lane
     * REACHED, and the commentary is only specific when it has both: "writes
     * Scene3Code.tsx" comes from the first, "stopped at the step ceiling" from
     * the second.
     *
     * @param  Collection<int, BenchmarkLaneActivity>  $activities
     * @param  Collection<int, object>  $operations
     * @return list<array{at: string, lane: string, kind: string, detail: string}>
     */
    private function feed(BenchmarkRun $run, Collection $activities, Collection $operations): array
    {
        $lanes = $run->lanes->mapWithKeys(fn ($lane): array => [
            $lane->id => sprintf('Lane %d (%s %s)', $lane->ordinal, $lane->provider, $lane->model),
        ]);

        $rows = $activities->map(fn (BenchmarkLaneActivity $event): array => [
            'at' => (string) $event->created_at,
            'lane' => $lanes[$event->benchmark_lane_id] ?? 'Lane ?',
            'kind' => (string) $event->kind,
            'detail' => mb_substr((string) $event->summary, 0, 160),
        ])->values()->all();

        foreach ($operations as $operation) {
            $metadata = is_string($operation->metadata ?? null) ? json_decode($operation->metadata, true) : null;
            $tool = is_array($metadata) ? ($metadata['tool_name'] ?? null) : null;

            $rows[] = [
                'at' => (string) $operation->started_at,
                'lane' => $lanes[$operation->benchmark_lane_id] ?? 'Lane ?',
                'kind' => (string) $operation->kind,
                'detail' => is_string($tool) ? $tool : (string) $operation->status,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_slice($rows, -self::BATCH);
    }

    /** @param list<array{at: string, lane: string, kind: string, detail: string}> $events */
    private function prompt(BenchmarkRun $run, array $events): string
    {
        $feed = implode("\n", array_map(
            fn (array $event): string => sprintf('- %s %s: %s', $event['lane'], $event['kind'], $event['detail']),
            $events,
        ));

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
