<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One lab:docs-run (or /lab/docs run): one lane over one selection of scenarios. */
final class DocsLabRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @return HasMany<DocsLabResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(DocsLabResult::class, 'run_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['agent' => 'array', 'passed' => 'boolean', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
