<?php

declare(strict_types=1);

namespace App\Lab;

use RuntimeException;

final class SessionNotReady extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Lab session preflight is not ready.', 503);
    }
}
