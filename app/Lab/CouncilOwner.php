<?php

declare(strict_types=1);

namespace App\Lab;

use App\Models\User;

/** Council's email list narrows the existing owner; it never adds an owner. */
final readonly class CouncilOwner
{
    public function __construct(private OwnerIdentity $identity) {}

    public function allows(mixed $principal): bool
    {
        $current = $this->identity->current();
        if (! $principal instanceof User || ! $current instanceof User
            || (string) $principal->getAuthIdentifier() !== (string) $current->getAuthIdentifier()) {
            return false;
        }

        // Re-read the configured row: an old guard model must not retain access
        // after its account is deleted or its login email changes.
        $owner = $this->identity->configuredOwner();
        if (! $owner instanceof User || $principal->email !== $owner->email) {
            return false;
        }

        $owners = config('council.owners');
        if (! is_array($owners) || ! array_is_list($owners) || count($owners) !== 1 || ! is_string($owners[0])) {
            return false;
        }

        $email = $owner->email;
        if (! is_string($email) || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return mb_strtolower(trim($owners[0])) === mb_strtolower(trim($email));
    }
}
