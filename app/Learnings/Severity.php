<?php

declare(strict_types=1);

namespace App\Learnings;

enum Severity: string
{
    /** True, worth keeping, nothing is on fire. */
    case Info = 'info';

    /** Someone should look at this before the next release. */
    case Notable = 'notable';

    /** Something is wrong now and shipping over it would make it worse. */
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
