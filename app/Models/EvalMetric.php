<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EvalMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['neutral' => 'boolean', 'summary' => 'array', 'delta' => 'array'];
    }
}
