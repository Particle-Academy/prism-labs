<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Lab\OwnerIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireLabOwner
{
    public function __construct(private OwnerIdentity $owner) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->owner->requireOwner();

        return $next($request);
    }
}
