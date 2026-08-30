<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class BenchmarkSpec extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'specification' => 'array', 'rubric' => 'array', 'lane_matrix' => 'array', 'budgets' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
