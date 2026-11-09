<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Lab\ModelCatalogue;
use App\Lab\ModelPolicy;
use App\Lab\ModelPrices;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\SessionNotReady;
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
 * Provider failures do not throw. Missing authority refuses before inference.
 * An unscored lane is a gap; a lane whose RESULT was lost because
 * its judge failed is corruption.
 */
final readonly class BenchmarkScorer
{
    public function __construct(private LabSession $sessions, private ModelCatalogue $catalogue, private ModelPolicy $modelPolicy, private ModelPrices $prices) {}

    public function score(BenchmarkLane $lane): ScoringResult
    {
        if ($lane->score !== null) {
            return new ScoringResult((float) $lane->score);
        }
        BenchmarkWork::assertRun($lane->benchmarkRun);
        app(PaidRunContext::class)->authorizedWork('benchmark.score', $lane->id, BenchmarkWork::scorePayload($lane));
        try {
            return $this->judge($lane);
        } catch (PaidRunNotAuthorised|SessionNotReady $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            report($failure);

            return new ScoringResult(null, 'Not scored: the judge failed. No automatic inference retry.');
        }
    }

    private function judge(BenchmarkLane $lane): ScoringResult
    {
        if ($lane->score !== null) {
            return new ScoringResult((float) $lane->score);
        }
        if ($lane->activities()->where('kind', 'scoring.started')->exists()) {
            return new ScoringResult(null, 'Not scored: previous judging delivery is uncertain. No automatic inference retry.');
        }

        $spec = $lane->benchmarkRun?->spec;

        if ($spec === null || ! is_array($lane->proof) || ! isset($lane->proof['checks'])) {
            return new ScoringResult(null, 'Not scored: no checkable proof or specification is available.');
        }

        $rubric = Rubric::fromSpec($spec);

        if ($rubric->isEmpty()) {
            return new ScoringResult(null, 'Not scored: no scoring rubric is defined.');
        }

        $model = $this->catalogue->cheapestConfigured($this->modelPolicy->allowed());

        if ($model === null) {
            return $this->unavailableModel();
        }

        // Persist before send: a worker crash must not turn a listing retry into repeated inference.
        BenchmarkWork::assertRun($lane->benchmarkRun);
        app(PaidRunContext::class)->requireWork('benchmark.score', $lane->id, BenchmarkWork::scorePayload($lane));
        app(LaneActivity::class)->record($lane, 'scoring.started', 'Judging attempt started; no completed score yet. An uncertain delivery will not be automatically repeated.');
        $response = $this->sessions->resolveScope('scoring:'.$lane->id)
            ->usingMode('scoring')
            ->usingProvider($model['provider'])
            ->usingModel($model['model'])
            ->send($this->prompt($lane, $spec, $rubric));

        $scored = $this->parse($response->text(), $rubric);

        if ($scored === []) {
            return new ScoringResult(null, 'Not scored: the judge returned no usable scores. No automatic inference retry.');
        }

        return new ScoringResult($this->persist($lane, $rubric, $scored));
    }

    private function unavailableModel(): ScoringResult
    {
        $selection = $this->modelPolicy->selection();
        if ($selection === []) {
            return new ScoringResult(null, 'Not scored: no model is enabled for judging.');
        }

        $providers = array_column($this->catalogue->providers(), null, 'key');
        $states = [];
        $listingStates = [];
        foreach ($selection as $key) {
            [$provider, $id] = array_pad(explode(':', $key, 2), 2, '');
            $listingStates[] = $providers[$provider]['status'] ?? 'unconfigured';
            if ($this->prices->forModel($provider, $id) !== null) {
                $states[] = $providers[$provider]['status'] ?? 'unconfigured';
            }
        }

        if (in_array('authentication_rejected', $listingStates, true)) {
            return new ScoringResult(null, 'Not scored: the provider rejected a selected API key or its access. Update the Lab configuration before retrying.');
        }
        if (in_array('unreachable', $states, true)) {
            return new ScoringResult(null, "Not scored: couldn't reach the provider's model list.", true);
        }
        if (in_array('unconfigured', $listingStates, true)) {
            return new ScoringResult(null, 'Not scored: a selected provider has no configured API key or endpoint.');
        }
        if (in_array('unsupported', $listingStates, true)) {
            return new ScoringResult(null, 'Not scored: model listing is unsupported for a selected provider.');
        }
        if (in_array('ready', $states, true)) {
            return new ScoringResult(null, 'Not scored: the selected priced model is no longer offered.');
        }

        return new ScoringResult(null, 'Not scored: no permitted model has a checked price.');
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
            // The agent's CLAIM, labelled as one. The measured facts about that
            // file arrive as the `lab-measured-artifact` receipt below, and the
            // prompt says which is which — a judge shown one number cannot tell
            // whether anybody checked it.
            'ARTIFACT CLAIMED BY THE AGENT: '.($lane->proof['working_artifact'] ?? 'none named'),
            'The receipts below include a `lab-measured-artifact` entry written by the Lab, not by the agent: '
            .'it is the existence, byte size and sha256 of that file as read off disk. Judge the work from the '
            .'artifact and the receipts, never from the agent\'s account of what it submitted.',
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
