<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Lab\LabDatabase;
use App\Lab\PaidRunNotAuthorised;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

$application = Application::configure(basePath: dirname(__DIR__))
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
        ]);

        // Trust the deploy proxy so signed URLs / OAuth redirects use HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PaidRunNotAuthorised $failure, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'paid_call_not_authorised', 'message' => $failure->getMessage()], 403);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            // Lab forms use fetch with Accept: application/json. Redirecting
            // validation failures gave them HTML instead of actionable errors.
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Before RegisterProviders: even a provider that uses the database while
// registering cannot reach the shared workspace/Sandbox through stale config.
$application->afterBootstrapping(LoadConfiguration::class, static function (#[SensitiveParameter] Application $application): void {
    LabDatabase::assertIsolated($application);
});

return $application;
