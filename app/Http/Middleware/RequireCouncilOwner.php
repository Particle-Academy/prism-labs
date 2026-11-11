<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Lab\CouncilOwner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireCouncilOwner
{
    public function __construct(private CouncilOwner $owner) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->owner->allows($request->user('web')), 403, 'The authenticated Lab council owner is required.');

        return $next($request);
    }
}
