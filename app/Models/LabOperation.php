<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LabOperation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array', 'cost' => 'decimal:8',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
