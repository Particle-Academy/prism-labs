<?php

declare(strict_types=1);

namespace App\Team;

/**
 * Who is on the team, and what is honestly true about each one.
 *
 * The roster is configuration, not discovery. A lane that is PLANNED has no
 * repository to discover, and a lane that is down should read as down rather
 * than vanishing — a board built purely from what answered would quietly
 * shrink to the healthy members, which is the opposite of what it is for.
 */
final class AgentRoster
{
    /** @var list<Agent>|null */
    private ?array $agents = null;

    /**
     * @return list<Agent>
     */
    public function all(): array
    {
        return $this->agents ??= [
            new Agent(
                name: 'Prism.php',
                language: 'php',
                state: AgentState::Coordinator,
                repo: 'prism-labs',
                note: 'Runs on prism-harness. Coordinates the team and files 0L reports.',
            ),
            new Agent(
                name: 'prism.ts',
                language: 'ts',
                state: AgentState::Live,
                repo: 'prism-ts',
                endpoint: (string) config('team.endpoints.ts'),
                note: 'Reasons through prism-ts itself, so the port is its own most demanding consumer.',
            ),
            new Agent(
                name: 'prism.py',
                language: 'py',
                state: AgentState::Live,
                repo: 'prism-py',
                endpoint: (string) config('team.endpoints.py'),
                note: 'Reasons through prism-py itself.',
            ),
            new Agent(
                name: 'prism.rust',
                language: 'rust',
                state: AgentState::Planned,
                note: 'No prism-rust repository yet. The port comes first, then the agent.',
            ),
            new Agent(
                name: 'prism.go',
                language: 'go',
                state: AgentState::Planned,
                note: 'No prism-go repository yet. The port comes first, then the agent.',
            ),
        ];
    }

    public function find(string $language): ?Agent
    {
        foreach ($this->all() as $agent) {
            if ($agent->language === $language) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * The lanes Prism.php can actually delegate to.
     *
     * @return list<Agent>
     */
    public function addressable(): array
    {
        return array_values(array_filter($this->all(), fn (Agent $a): bool => $a->state->isAddressable()));
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_map(fn (Agent $a): string => $a->language, $this->all());
    }
}
