<?php

declare(strict_types=1);

namespace App\Team;

use Prism\Mcp\Facades\PrismMcp;
use Throwable;

/**
 * Talks to one language agent over MCP.
 *
 * Everything that comes back is UNTRUSTED. It is model output that arrived
 * over a network boundary, so it is data for the coordinator to weigh and
 * never instruction to follow. The framing this class returns keeps that
 * visible in the payload rather than relying on the reader to remember.
 */
final class LanguageAgent
{
    public function __construct(private readonly Agent $agent) {}

    /**
     * Cheap, and the only call that decides whether a lane is live.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->call('status', [], (float) config('team.timeouts.status'));
    }

    public function offers(string $tool): bool
    {
        return $this->probe($tool)->isOffered();
    }

    /**
     * Whether this agent offers a tool, and when it does not, WHY.
     *
     * The bool above cannot distinguish an agent that answered and lacks the
     * tool from one that never answered at all, and those need opposite
     * responses from whoever reads the result. See {@see CapabilityProbe}.
     */
    /**
     * The tools this agent actually offers, or null when it could not be asked.
     *
     * Null and [] are different answers and are kept apart: an agent that did
     * not respond has an unknown toolset, and an agent that responded with
     * nothing has an empty one.
     *
     * @return list<string>|null
     */
    public function toolNames(): ?array
    {
        if ($this->agent->endpoint === null || $this->agent->endpoint === '') {
            return null;
        }

        try {
            return array_map(
                fn (object $definition): string => (string) $definition->name,
                PrismMcp::client($this->agent->endpoint)
                    ->withTimeout((float) config('team.timeouts.status'))
                    ->withoutCache()
                    ->client()
                    ->definitions(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function probe(string $tool): CapabilityProbe
    {
        if ($this->agent->endpoint === null || $this->agent->endpoint === '') {
            return CapabilityProbe::NoEndpoint;
        }

        try {
            $definitions = PrismMcp::client($this->agent->endpoint)
                ->withTimeout((float) config('team.timeouts.status'))
                ->withoutCache()
                ->client()
                ->definitions();

            foreach ($definitions as $definition) {
                if ($definition->name === $tool) {
                    return CapabilityProbe::Offered;
                }
            }
        } catch (Throwable) {
            // The endpoint exists and did not answer. Reported as its own
            // state rather than folded into "does not offer it", because a
            // process that is down comes back and a missing tool does not.
            return CapabilityProbe::Unreachable;
        }

        return CapabilityProbe::NotOffered;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments = [], ?float $timeout = null): array
    {
        if ($this->agent->endpoint === null || $this->agent->endpoint === '') {
            return $this->unreachable('this lane has no endpoint configured');
        }

        try {
            $result = PrismMcp::client($this->agent->endpoint)
                ->withTimeout($timeout ?? (float) config('team.timeouts.work'))
                // The tool list is small and the agents restart often during
                // development; a stale cached list would have the coordinator
                // calling a tool that no longer exists.
                ->withoutCache()
                ->client()
                ->callTool($tool, $arguments);
        } catch (Throwable $e) {
            // A lane being down is an ANSWER, not an exception to propagate.
            // The board needs to render "unreachable" for one member without
            // the whole roster call failing.
            return $this->unreachable($e->getMessage());
        }

        $structured = $result->structuredContent;
        $semanticRefusal = is_array($structured) && ($structured['ok'] ?? null) === false;

        return [
            'ok' => ! $result->isError && ! $semanticRefusal,
            'language' => $this->agent->language,
            'data' => $structured,
            'text' => $result->text(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unreachable(string $reason): array
    {
        return [
            'ok' => false,
            'language' => $this->agent->language,
            'unreachable' => true,
            'reason' => $reason,
            'data' => null,
        ];
    }
}
