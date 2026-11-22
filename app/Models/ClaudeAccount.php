<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An added Claude account: a label and the CLI configuration directory the Lab made for it. Nothing else is stored.
 *
 * @property int $id
 * @property string $label
 * @property string $path
 * @property bool $active
 */
final class ClaudeAccount extends Model
{
    protected $fillable = ['label', 'path', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
