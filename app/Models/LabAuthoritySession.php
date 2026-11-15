<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LabAuthoritySession extends Model
{
    use HasUuids;

    protected $fillable = ['owner_user_id'];

    protected function casts(): array
    {
        return ['owner_user_id' => 'integer', 'allocated_microusd' => 'integer'];
    }
}
