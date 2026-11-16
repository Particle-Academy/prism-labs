<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * What one provider says it billed over a range of UTC days.
 *
 * A side that could not be read is null with a reason, never an empty array:
 * an empty array is a reading ("billed nothing"), and the reconciler treats it
 * as one.
 */
final readonly class BilledPeriod
{
    public const UNATTRIBUTED_MODEL = '(no model)';

    /**
     * @param  array<string, array<string, array<string, int>>>|null  $tokens  [day][model] => input (uncached), cached_input, cache_write, output, audio, requests
     * @param  array<string, array<string, float>>|null  $cost  [day][model] => USD; model is '*' when $costByModel is false
     * @param  CarbonImmutable|null  $freshThrough  billing data is complete up to this instant; null with $tracksFreshness means "nothing refreshed yet"
     */
    public function __construct(
        public ?array $tokens,
        public ?string $tokensFailure,
        public ?array $cost,
        public ?string $costStatus,
        public ?string $costReason,
        public bool $costByModel,
        public bool $tracksFreshness = false,
        public ?CarbonImmutable $freshThrough = null,
    ) {}

    /** @param array<string, int> $row */
    public static function totalTokens(array $row): int
    {
        return ($row['input'] ?? 0) + ($row['cached_input'] ?? 0) + ($row['cache_write'] ?? 0)
            + ($row['output'] ?? 0) + ($row['audio'] ?? 0);
    }
}
