<?php

declare(strict_types=1);

namespace App\Learnings;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $ref
 * @property string $title
 * @property string $filed_by
 * @property Severity $severity
 * @property array<int, string> $languages
 * @property string $what_was_learned
 * @property string $evidence
 * @property string $why_it_matters
 * @property string|null $what_should_change
 * @property string $path
 */
final class Learning extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'severity' => Severity::class,
        ];
    }
}
