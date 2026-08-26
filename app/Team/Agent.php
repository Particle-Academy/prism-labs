<?php

declare(strict_types=1);

namespace App\Team;

/**
 * One member of the team.
 *
 * `endpoint` is where its MCP server listens. Null for the coordinator (it is
 * here) and for a PLANNED lane (there is nothing to listen).
 */
final readonly class Agent
{
    public function __construct(
        public string $name,
        public string $language,
        public AgentState $state,
        public ?string $repo = null,
        public ?string $endpoint = null,
        public ?string $note = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'repo' => $this->repo,
            'addressable' => $this->state->isAddressable(),
            'note' => $this->note,
        ];
    }
}
