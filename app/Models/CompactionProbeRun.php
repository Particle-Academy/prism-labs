<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CompactionProbeRun extends Model
{
    protected $guarded = [];

    /**
     * The evidence flags are cast, not just `reserved`.
     *
     * The page types them as booleans and they reached it as 0/1, which happens
     * to render the same through a ternary and would not survive anyone writing
     * a strict comparison against `false`. Declaring them keeps the TypeScript
     * type on the other side of the wire honest.
     */
    protected function casts(): array
    {
        return [
            'reserved' => 'boolean',
            'looked' => 'boolean',
            'correct' => 'boolean',
            'fact_left_window' => 'boolean',
            'confabulated' => 'boolean',
        ];
    }
}
