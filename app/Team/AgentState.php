<?php

declare(strict_types=1);

namespace App\Team;

enum AgentState: string
{
    /** Runs here, in the PHP harness. Coordinates the rest. */
    case Coordinator = 'coordinator';

    /** A port exists, an agent is built on it, and it answered last time we asked. */
    case Live = 'live';

    /** The port exists and the agent is built, but it did not answer. */
    case Unreachable = 'unreachable';

    /**
     * No repository yet. On the board deliberately.
     *
     * A roster that lists only what works reads as full coverage, and the gap
     * between "we test five languages" and "two of them do not exist" is
     * exactly the thing a board is for.
     */
    case Planned = 'planned';

    public function label(): string
    {
        return match ($this) {
            self::Coordinator => 'Coordinator',
            self::Live => 'Live',
            self::Unreachable => 'Unreachable',
            self::Planned => 'Planned',
        };
    }

    /** Whether Prism.php should try to talk to it at all. */
    public function isAddressable(): bool
    {
        return $this === self::Live || $this === self::Unreachable;
    }
}
