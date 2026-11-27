<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EvalTrial extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reward' => 'float', 'cost_usd' => 'float', 'wall_seconds' => 'float'];
    }
}
