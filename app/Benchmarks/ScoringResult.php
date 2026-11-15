<?php

declare(strict_types=1);

namespace App\Benchmarks;

/** A listing retry is possible only before any inference has been sent. */
final readonly class ScoringResult
{
    public function __construct(public ?float $score, public ?string $reason = null, public bool $retryListing = false) {}
}
