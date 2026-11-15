<?php

declare(strict_types=1);

namespace App\Lab;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaidRunNotAuthorised extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Paid run authority is missing, expired, revoked or does not match this work.');
    }
}
