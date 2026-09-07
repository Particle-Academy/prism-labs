<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // GitHub delivers the contribution webhook without a CSRF token; it is
        // authenticated by its HMAC signature instead.
        $middleware->validateCsrfTokens(except: [
            'webhooks/github',
            // Driven by an external benchmark harness, which holds no session
            // and therefore no token. Safe because the route is local-only
            // behind EnsurePrismLabIsLocal, and because it mutates nothing —
            // it asks the model a question and returns the answer.
            'lab/benchmarks/policy-turn',
        ]);

        // Trust the deploy proxy so signed URLs / OAuth redirects use HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
