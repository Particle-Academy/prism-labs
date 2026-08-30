<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchmarkRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<BenchmarkSpec, $this> */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(BenchmarkSpec::class, 'benchmark_spec_id');
    }

    /** @return HasMany<BenchmarkLane, $this> */
    public function lanes(): HasMany
    {
        return $this->hasMany(BenchmarkLane::class)->orderBy('ordinal');
    }

    protected function casts(): array
    {
        return [
            'randomized_order' => 'array', 'started_at' => 'datetime',
            'finished_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }
}
