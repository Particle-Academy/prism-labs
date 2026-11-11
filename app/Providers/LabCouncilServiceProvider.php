<?php

declare(strict_types=1);

namespace App\Providers;

use App\Lab\CouncilOwner;
use App\Lab\OwnerIdentity;
use ChangeCouncil\Facades\Council;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class LabCouncilServiceProvider extends ServiceProvider
{
    public function boot(CouncilOwner $owner, OwnerIdentity $identity): void
    {
        // Define this before the package's email-only default. The predicate
        // reads the current request and account on each check, never on boot.
        Gate::define('council-owner', fn (mixed $user): bool => $owner->allows($user));
        Council::authorizeOwners();
        // authorizeOwners installs the package's email authorizer separately
        // from its Gate. Narrow native API calls to the same session/ID rule.
        Council::authorizeUsing(fn (mixed $principal): bool => $owner->allows($principal));
        // The native resolver validates the string and refuses null with
        // invalid_actor. Never fall back to an email or a caller's label.
        Council::resolveActorUsing(function (mixed $principal) use ($owner, $identity): ?string {
            $current = $identity->current();

            return $current !== null && $owner->allows($principal)
                ? (string) $current->getAuthIdentifier()
                : null;
        });
        Council::notifyGenie();
    }
}
