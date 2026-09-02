<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkSpec;

/**
 * A rubric, in one shape, whatever shape it was written in.
 *
 * Two forms are already frozen into approved specs and both must keep working,
 * because a spec cannot be edited once approved — only superseded:
 *
 *   {"dimensions":[{"name":"Correctness","weight":0.25,"criteria":"…"}]}
 *   {"functional":40,"fancy_fidelity":25,"human_plus":20,"quality":15}
 *
 * The first carries fractions and prose criteria; the second is a bare
 * name-to-percentage map with no criteria at all. Weights are normalised to sum
 * to 1 so a judge's per-dimension scores can be combined the same way in both
 * cases — a rubric written as percentages and one written as fractions must not
 * produce totals on different scales.
 */
final readonly class Rubric
{
    /** @param list<array{name: string, weight: float, criteria: ?string}> $dimensions */
    private function __construct(public array $dimensions) {}

    public static function fromSpec(BenchmarkSpec $spec): self
    {
        $rubric = is_array($spec->rubric) ? $spec->rubric : [];
        $raw = is_array($rubric['dimensions'] ?? null) ? $rubric['dimensions'] : null;

        $dimensions = $raw === null
            ? self::fromFlatWeights($rubric)
            : self::fromDimensionList($raw);

        return new self(self::normalise($dimensions));
    }

    /** @return list<array{name: string, weight: float, criteria: ?string}> */
    private static function fromFlatWeights(array $rubric): array
    {
        $dimensions = [];

        foreach ($rubric as $name => $weight) {
            if (! is_string($name) || ! is_numeric($weight)) {
                continue;
            }

            // No criteria to give the judge. That is a real weakness of this
            // rubric shape and it is surfaced rather than papered over: the
            // judge is told the dimension has no stated criteria, so it scores
            // against the acceptance checks instead of inventing a standard.
            $dimensions[] = ['name' => $name, 'weight' => (float) $weight, 'criteria' => null];
        }

        return $dimensions;
    }

    /** @return list<array{name: string, weight: float, criteria: ?string}> */
    private static function fromDimensionList(array $raw): array
    {
        $dimensions = [];

        foreach ($raw as $dimension) {
            if (! is_array($dimension) || ! is_string($dimension['name'] ?? null)) {
                continue;
            }

            $weight = $dimension['weight'] ?? null;
            $criteria = $dimension['criteria'] ?? null;

            $dimensions[] = [
                'name' => $dimension['name'],
                'weight' => is_numeric($weight) ? (float) $weight : 0.0,
                'criteria' => is_string($criteria) ? $criteria : null,
            ];
        }

        return $dimensions;
    }

    /**
     * Weights sum to 1, or they are equal.
     *
     * A rubric whose weights are all zero or missing is not a reason to refuse
     * to score — it is a reason to weight every dimension the same and say so.
     * Refusing would mean a badly written rubric silently produces no score at
     * all, which is the failure mode this whole surface exists to end.
     *
     * @param  list<array{name: string, weight: float, criteria: ?string}>  $dimensions
     * @return list<array{name: string, weight: float, criteria: ?string}>
     */
    private static function normalise(array $dimensions): array
    {
        if ($dimensions === []) {
            return [];
        }

        $total = array_sum(array_column($dimensions, 'weight'));

        if ($total <= 0.0) {
            $equal = 1.0 / count($dimensions);

            return array_map(fn (array $d): array => [...$d, 'weight' => $equal], $dimensions);
        }

        return array_map(fn (array $d): array => [...$d, 'weight' => $d['weight'] / $total], $dimensions);
    }

    public function isEmpty(): bool
    {
        return $this->dimensions === [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->dimensions, 'name');
    }

    public function weightFor(string $name): float
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension['name'] === $name) {
                return $dimension['weight'];
            }
        }

        return 0.0;
    }
}
