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

    /**
     * The independently checkable evidence this lane submitted.
     *
     * A lane fails closed without at least one, so a completed lane always has
     * receipts — and until now nothing ever showed them, which made "PLabs
     * scores only evidence-backed receipts" a claim the Lab asserted about
     * itself and never displayed.
     *
     * @return HasMany<BenchmarkReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(BenchmarkReceipt::class)->orderBy('created_at');
    }

    protected function casts(): array
    {
        return [
            'proof' => 'array', 'cost' => 'decimal:8', 'score' => 'decimal:4',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
