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

        return [
            'ok' => ! $result->isError,
            'language' => $this->agent->language,
            'data' => $result->structuredContent,
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
