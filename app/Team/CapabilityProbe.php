<?php

declare(strict_types=1);

namespace App\Team;

/**
 * Why a language lane can or cannot run a given tool.
 *
 * `offers()` returned a bool, and four genuinely different situations arrived
 * as the same `false`:
 *
 *   - no agent is registered for that language at all;
 *   - one is registered but has no endpoint configured;
 *   - it has an endpoint and the probe failed (Throwable was swallowed);
 *   - it answered, and does not offer the tool.
 *
 * The preflight then printed one sentence for all four — "The parity agent is
 * not a Harness" — which is only true of the last. For an agent that is simply
 * not running, that sentence is actively wrong: it sends someone to rewrite a
 * port when the fix is to start a process.
 *
 * Same shape as SubagentOutcome and prism-sandbox's AwardOutcome, and the same
 * reason. See prism-parity decision 0020.
 */
enum CapabilityProbe: string
{
    case Offered = 'offered';
    case Unregistered = 'unregistered';
    case NoEndpoint = 'no_endpoint';
    case Unreachable = 'unreachable';
    case NotOffered = 'not_offered';

    public function isOffered(): bool
    {
        return $this === self::Offered;
    }

    /**
     * Whether trying again, unchanged, could plausibly succeed.
     *
     * Only the unreachable case: a process that is down can come back up.
     * Everything else needs a person to change something first.
     */
    public function isTransient(): bool
    {
        return $this === self::Unreachable;
    }

    public function explain(string $language, string $tool): string
    {
        return match ($this) {
            self::Offered => sprintf('%s offers %s.', $language, $tool),
            self::Unregistered => sprintf('%s has no agent in the roster, so nothing could be asked for %s.', $language, $tool),
            self::NoEndpoint => sprintf('The %s agent is registered but has no endpoint configured, so %s cannot be reached.', $language, $tool),
            self::Unreachable => sprintf('The %s agent did not answer. It may simply not be running — this is the one cause that can fix itself.', $language),
            self::NotOffered => sprintf('The %s agent answered and does not offer %s. It is a parity agent, not a Harness.', $language, $tool),
        };
    }
}
