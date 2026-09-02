<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ConsensusRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * The opinions this run collected.
     *
     * Ordered by `agent` rather than by insertion, so the same roster reads in
     * the same order on every run — a reviewer comparing two runs side by side
     * should not have to re-find which column is which.
     *
     * @return HasMany<ConsensusResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(ConsensusResponse::class)->orderBy('agent');
    }

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'pushed_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }
}
