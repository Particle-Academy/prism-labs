<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchmarkLane extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<BenchmarkRun, $this> */
    public function benchmarkRun(): BelongsTo
    {
        return $this->belongsTo(BenchmarkRun::class);
    }

    /** @return HasMany<BenchmarkLaneActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(BenchmarkLaneActivity::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'proof' => 'array', 'cost' => 'decimal:8', 'score' => 'decimal:4',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
