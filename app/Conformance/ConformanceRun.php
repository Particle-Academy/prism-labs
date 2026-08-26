<?php

declare(strict_types=1);

namespace App\Conformance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $corpus_version
 * @property string $corpus_digest
 */
final class ConformanceRun extends Model
{
    protected $guarded = [];

    /**
     * @return HasMany<ConformanceResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(ConformanceResult::class);
    }

    /**
     * Cases where the languages did not agree.
     *
     * The interesting query, and the reason results are stored per case: two
     * lanes can report identical totals while disagreeing on which cases they
     * are. Grouped by case, a disagreement is a group with more than one
     * distinct status.
     *
     * @return list<array{suite: string, case_id: string, statuses: array<string, string>, reasons: array<string, string|null>}>
     */
    public function disagreements(): array
    {
        $grouped = [];

        foreach ($this->results()->orderBy('suite')->orderBy('case_id')->get() as $result) {
            $key = $result->suite.'/'.$result->case_id;
            $grouped[$key]['suite'] = $result->suite;
            $grouped[$key]['case_id'] = $result->case_id;
            $grouped[$key]['statuses'][$result->language] = $result->status;
            $grouped[$key]['reasons'][$result->language] = $result->reason;
        }

        return array_values(array_filter(
            $grouped,
            fn (array $case): bool => count(array_unique($case['statuses'])) > 1,
        ));
    }
}
