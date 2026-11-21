<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * A billed figure that could not be read.
 *
 * Carries a short machine reason ONLY ("http_401", "connection_failed",
 * "malformed_response"). Never the upstream message or body: a provider error
 * body can echo request details, and this text is stored and rendered.
 */
final class BillingUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Billing data unavailable: '.$reason);
    }
}
