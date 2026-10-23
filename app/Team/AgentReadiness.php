<?php

declare(strict_types=1);

namespace App\Team;

/** Free capability and configuration probes; never asks a model a question. */
final readonly class AgentReadiness
{
    public function __construct(private AgentRoster $roster) {}

    /** @return list<array<string, mixed>> */
    public function lanes(): array
    {
        $lanes = [];
        foreach ($this->roster->addressable() as $agent) {
            $client = new LanguageAgent($agent);
            $status = $client->status();
            $data = is_array($status['data'] ?? null) ? $status['data'] : [];
            $tools = ($status['ok'] ?? false) === true ? $client->toolNames() : null;
            $reason = match (true) {
                ($status['ok'] ?? false) !== true => $status['reason'] ?? 'The agent did not answer its status probe.',
                $tools === null => 'The agent answered status but its tool list could not be read.',
                ($data['agent_stale'] ?? null) !== false => 'The agent is stale or could not prove that it loaded the current code. Restart it after building the port.',
                ($data['provider_available'] ?? null) !== true => 'The configured provider is unavailable in this port.',
                ($data['can_reason'] ?? null) !== true => 'The agent has no provider credentials configured.',
                default => null,
            };
            $capabilities = [];
            foreach (['consensus', 'benchmark'] as $tool) {
                $missing = $tools !== null && ! in_array($tool, $tools, true);
                $capabilities[$tool] = [
                    'ready' => $reason === null && ! $missing,
                    'reason' => $missing
                        ? ($tool === 'benchmark'
                            ? 'This port has no benchmark lane executor (G-10). Starting the agent does not supply one.'
                            : 'This agent does not offer consensus. Build the current port and restart its agent.')
                        : $reason,
                ];
            }
            $lanes[] = [
                'agent' => $agent->name, 'language' => $agent->language,
                'port_version' => $data['port_version'] ?? null,
                'agent_stale' => $data['agent_stale'] ?? null,
                'capabilities' => $capabilities,
            ];
        }

        return $lanes;
    }
}
