<?php

declare(strict_types=1);

namespace App\Lab;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class OwnerIdentity
{
    public function configured(): bool
    {
        return $this->configuredId() !== null;
    }

    public function current(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User && $this->matches($user) && $this->configuredOwner() !== null ? $user : null;
    }

    /** Existing participant metadata, never a login or an account provisioner. */
    public function configuredOwner(): ?User
    {
        $id = $this->configuredId();

        return $id === null ? null : User::query()->find($id);
    }

    public function matches(User $user): bool
    {
        $id = $this->configuredId();

        return $id !== null && (string) $user->getAuthIdentifier() === (string) $id;
    }

    public function requireOwner(): User
    {
        $user = $this->current();
        abort_unless($user instanceof User, 403, 'The authenticated Lab owner is required.');

        return $user;
    }

    private function configuredId(): ?int
    {
        $value = config('lab_authority.owner_user_id');
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) && (string) $id === $value ? $id : null;
    }
}
