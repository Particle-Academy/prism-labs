<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One imported, attestation-verified harness-benchmark run (G-393). */
final class EvalRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['cap_usd' => 'float', 'spent_usd' => 'float', 'attestation_verified_at' => 'immutable_datetime'];
    }

    /** @return HasMany<EvalTrial, $this> */
    public function trials(): HasMany
    {
        return $this->hasMany(EvalTrial::class);
    }

    /** @return HasMany<EvalMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(EvalMetric::class);
    }
}
