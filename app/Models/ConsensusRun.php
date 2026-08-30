<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConsensusRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'pushed_at' => 'datetime'];
    }
}
