<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BenchmarkScore extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['weight' => 'float', 'score' => 'float'];
    }
}
