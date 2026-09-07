<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CompactionProbeRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reserved' => 'boolean',
        ];
    }
}
