<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\OwnerIdentity;
use App\Lab\RunAuthority;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class OwnerSessionController extends Controller
{
    public function show(Request $request, OwnerIdentity $identity, RunAuthority $authority): Response
    {
        $isOwner = $identity->current() !== null;

        return Inertia::render('Lab/Owner', [
            'configured' => $identity->configured(),
            'isOwner' => $isOwner,
            'csrfToken' => $request->session()->token(),
            'allocations' => $isOwner ? $authority->allocations() : null,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request, OwnerIdentity $identity): RedirectResponse
    {
        abort_unless($identity->configured(), 403, 'The Lab owner has not been configured.');

        // TrustProxies accepts forwarded headers elsewhere in this local app.
        // Only the socket peer can identify the source of a login attempt.
        $key = 'lab-owner-login:'.hash('sha256', (string) $request->server->get('REMOTE_ADDR'));
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many owner login attempts. Try again shortly.');
        RateLimiter::hit($key, 60);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:4096'],
        ]);
        $guard = Auth::guard('web');
        abort_unless($guard instanceof SessionGuard, 503, 'The owner session guard is unavailable.');
        if (! $guard->attemptWhen($credentials, fn (Authenticatable $user): bool => $user instanceof User && $identity->matches($user))) {
            throw ValidationException::withMessages(['email' => 'The owner credentials could not be verified.']);
        }

        $request->session()->regenerate();

        // A login identifies the person. It approves no spec, run or spending.
        return redirect('/lab/owner')->with('status', 'Owner signed in. Paid runs still require approval and GO.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/lab/owner');
    }
}
