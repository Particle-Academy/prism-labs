<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A link in the docs-lab certificate chain; see docs/docs-agent-lab.md for the format. */
final class DocsLabCertificate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['body' => 'array', 'issued_at' => 'immutable_datetime'];
    }
}
