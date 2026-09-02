<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Lab\ModelCatalogue;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkScore;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Scores a completed lane against its frozen rubric, from its receipts.
 *
 * BLIND, and that is not a nicety. `BenchmarkDesigner::launch()` shuffles the
 * lanes so nothing downstream can be biased by the order the spec listed them
 * in — a judge shown `claude-opus-5` beside `gpt-4.1-mini` would hand back
 * exactly the bias that shuffle was bought to prevent. The judge sees the spec,
 * the rubric, the proof and the receipts. It never sees the provider or model.
 *
 * NARROWED: the `scoring` mode declares no tools. The Lab already argues this
 * for its verifier — "a verifier able to file its own finding is a second
 * author, not a check" — and a judge that could write the workspace it is
 * judging is the same defect wearing a different hat.
 *
 * Never throws. An unscored lane is a gap; a lane whose RESULT was lost because
 * its judge failed is corruption.
 */
final readonly class BenchmarkScorer
{
    public function __construct(private LabSession $sessions, private ModelCatalogue $catalogue) {}

    public function score(BenchmarkLane $lane): ?float
    {
        try {
            return $this->judge($lane);
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }
    }

    private function judge(BenchmarkLane $lane): ?float
    {
        $spec = $lane->benchmarkRun?->spec;

        if ($spec === null || ! is_array($lane->proof) || ! isset($lane->proof['checks'])) {
            return null;
        }

        $rubric = Rubric::fromSpec($spec);

        if ($rubric->isEmpty()) {
            return null;
        }

        $model = $this->catalogue->cheapestConfigured();

        if ($model === null) {
            return null;
        }

        $response = $this->sessions->resolveScope('scoring:'.$lane->id)
            ->usingMode('scoring')
            ->usingProvider($model['provider'])
            ->usingModel($model['model'])
            ->send($this->prompt($lane, $spec, $rubric));

        $scored = $this->parse($response->text(), $rubric);

        if ($scored === []) {
            return null;
        }

        return $this->persist($lane, $rubric, $scored);
    }

    /**
     * The judge's brief. Note what is absent: no provider, no model, no lane
     * ordinal — nothing that identifies who built this.
     */
    private function prompt(BenchmarkLane $lane, $spec, Rubric $rubric): string
    {
        $dimensions = implode("\n", array_map(
            fn (array $d): string => sprintf(
                '- %s (weight %.2f): %s',
                $d['name'], $d['weight'], $d['criteria'] ?? 'no stated criteria — score against the acceptance checks',
            ),
            $rubric->dimensions,
        ));

        $checks = implode("\n", array_map(
            fn (string $name, $value): string => sprintf('- %s: %s', $name, is_bool($value) ? ($value ? 'claimed pass' : 'claimed fail') : (string) $value),
            array_keys($lane->proof['checks']),
            array_values($lane->proof['checks']),
        ));

        $receipts = $lane->receipts->map(fn ($receipt): string => sprintf(
            "- kind `%s` (digest %s):\n%s",
            $receipt->kind,
            mb_substr((string) $receipt->digest, 0, 12),
            mb_substr(json_encode($receipt->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 2500),
        ))->implode("\n");

        return implode("\n\n", array_filter([
            'BENCHMARK: '.json_encode($spec->specification, JSON_UNESCAPED_SLASHES),
            "RUBRIC DIMENSIONS:\n".$dimensions,
            'SUBMITTED ARTIFACT: '.($lane->proof['working_artifact'] ?? 'none named'),
            "THE BUILDER'S OWN CLAIMED CHECKS (its account, not evidence):\n".$checks,
            $receipts === '' ? 'RECEIPTS: none were submitted.' : "RECEIPTS (the evidence):\n".$receipts,
            'Score every dimension. Return the JSON object and nothing else.',
        ]));
    }

    /**
     * @return list<array{name: string, score: float, justification: string, cited_receipt: ?string}>
     */
    private function parse(string $text, Rubric $rubric): array
    {
        // Models wrap JSON in fences however firmly they are asked not to.
        $json = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)) ?? '');

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The judge did not return JSON.');
        }

        $rows = is_array($decoded['dimensions'] ?? null) ? $decoded['dimensions'] : [];
        $names = $rubric->names();
        $scored = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;

            // A dimension the rubric does not have is DISCARDED, not stored. A
            // judge inventing a dimension would otherwise silently reweight the
            // rubric it was asked to apply.
            if (! is_string($name) || ! in_array($name, $names, true) || ! is_numeric($row['score'] ?? null)) {
                continue;
            }

            $justification = $row['justification'] ?? null;
            $cited = $row['cited_receipt'] ?? null;

            $scored[] = [
                'name' => $name,
                'score' => max(0.0, min(100.0, (float) $row['score'])),
                'justification' => is_string($justification) ? mb_substr($justification, 0, 2000) : '',
                'cited_receipt' => is_string($cited) && $cited !== '' ? $cited : null,
            ];
        }

        return $scored;
    }

    /**
     * @param  list<array{name: string, score: float, justification: string, cited_receipt: ?string}>  $scored
     */
    private function persist(BenchmarkLane $lane, Rubric $rubric, array $scored): float
    {
        // The total is weighted over the dimensions ACTUALLY scored, re-based
        // so a judge that skipped one does not silently deflate the result
        // toward zero — a missing dimension is missing evidence about the
        // judge, not a failure by the builder.
        $weight = array_sum(array_map(fn (array $row): float => $rubric->weightFor($row['name']), $scored));
        $total = $weight <= 0.0
            ? array_sum(array_column($scored, 'score')) / count($scored)
            : array_sum(array_map(fn (array $row): float => $row['score'] * $rubric->weightFor($row['name']), $scored)) / $weight;

        DB::transaction(function () use ($lane, $rubric, $scored, $total): void {
            foreach ($scored as $row) {
                BenchmarkScore::query()->updateOrCreate(
                    ['benchmark_lane_id' => $lane->id, 'dimension' => $row['name']],
                    [
                        'weight' => $rubric->weightFor($row['name']),
                        'score' => $row['score'],
                        'justification' => $row['justification'],
                        'cited_receipt' => $row['cited_receipt'],
                    ],
                );
            }

            $lane->forceFill(['score' => round($total, 4)])->save();
        });

        return round($total, 4);
    }
}
