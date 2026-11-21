<?php

declare(strict_types=1);

namespace App\DocsLab;

/**
 * One lab test: a brief, the document it starts from, and what passing means.
 *
 * The expectations are written BEFORE any lane runs, in the scenario file, so a
 * result is graded against what was asked and not against what came back.
 * `scripted` is the realistic tool-call script the free lane plays through
 * Prism's fake; the live lane ignores it and asks a real model.
 */
final readonly class Scenario
{
    /**
     * @param  array{saved: ?string, draft: ?string}  $store  lab document names for the two DocumentStore slots
     * @param  list<array<string, mixed>>  $expect
     * @param  list<list<array{tool: string, arguments: array<string, mixed>}>|string>  $scripted
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $title,
        public string $origin,
        public string $brief,
        public array $store,
        public array $expect,
        public string $judge,
        public array $scripted,
        public string $digest,
    ) {}

    /** What a run record and a certificate cite for this scenario. */
    public function identity(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'digest' => $this->digest];
    }
}
