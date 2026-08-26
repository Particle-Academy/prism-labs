<?php

declare(strict_types=1);

namespace App\Conformance;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $language
 * @property string $suite
 * @property string $case_id
 * @property string $status
 * @property string|null $reason
 */
final class ConformanceResult extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
