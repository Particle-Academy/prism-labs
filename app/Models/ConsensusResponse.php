<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ConsensusResponse extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'confidence' => 'decimal:4'];
    }
}
