<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One scenario's verdict in a run: every structural check, the judge's if it ran, and the artefacts. */
final class DocsLabResult extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<DocsLabRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DocsLabRun::class, 'run_id');
    }

    protected function casts(): array
    {
        return ['checks' => 'array', 'calls' => 'array', 'artefacts' => 'array', 'structural_passed' => 'boolean', 'passed' => 'boolean'];
    }
}
