<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePrismLabIsLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        // Use the raw socket peer, NOT $request->ip(): the app trusts all
        // proxies (for the GitHub webhook), so ip() would honour a client
        // supplied X-Forwarded-For and let a remote caller spoof loopback.
        $peer = $request->server->get('REMOTE_ADDR');

        abort_unless(
            app()->environment('local') && in_array($peer, ['127.0.0.1', '::1'], true),
            404,
        );

        return $next($request);
    }
}
