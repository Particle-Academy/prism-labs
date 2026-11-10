<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LabPaidWork extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['payload' => 'array', 'claimed_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
