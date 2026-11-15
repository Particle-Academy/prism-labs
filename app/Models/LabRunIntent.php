<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LabRunIntent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'cap_microusd' => 'integer',
            'approved_by' => 'integer', 'go_by' => 'integer',
            'expires_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'go_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
            'acp_host_claimed_at' => 'immutable_datetime', 'acp_prompt_claimed_at' => 'immutable_datetime',
        ];
    }
}
