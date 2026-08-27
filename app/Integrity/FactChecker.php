<?php

declare(strict_types=1);

namespace App\Integrity;

use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * Runs prism-parity's `factcheck.mjs` and hands the team a structured result.
 *
 * The script verifies that PROSE agrees with CODE across the ecosystem — a
 * documented class that exists, an artisan command that is declared, an install
 * line that resolves, a cited decision that is real. It is maintained in ONE
 * place (prism-parity) and is the same script CI runs, deliberately: a Lab that
 * checked documentation its own way would give the team a second opinion to
 * reconcile rather than an answer, and the two would drift.
 *
 * See prism-parity/docs/decisions/0019-checking-the-prose.md.
 *
 * WHY THE TEAM GETS THIS AT ALL. CI answers "is this branch clean". The team
 * answers a different question: whether a finding is worth a 0L, and what it
 * MEANS that documentation and code have parted company in a particular place.
 * A version-drift warning that CI shrugs at is exactly the kind of thing worth
 * filing when it has been shrugged at for a month.
 */
final class FactChecker
{
    public function __construct(
        private readonly string $script,
        private readonly int $timeout = 180,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $strict = false): array
    {
        if (! is_file($this->script)) {
            // Returned, not thrown. A missing checker is an answer the agent can
            // reason about and report; an exception mid-loop is a dead turn.
            return $this->unavailable(sprintf(
                'The fact-checker is not on disk at [%s]. prism-parity is probably not checked out beside this app.',
                $this->script,
            ));
        }

        $arguments = ['node', $this->script, '--json'];

        if ($strict) {
            $arguments[] = '--strict';
        }

        $result = Process::timeout($this->timeout)->run($arguments);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // The script exits non-zero when it finds something, so a failed
            // exit is NOT an error here — unparseable output is. Treating exit
            // code as the verdict would report every real finding as a broken
            // tool, which is how a team learns to ignore the tool.
            return $this->unavailable(trim($result->errorOutput()) ?: 'The fact-checker produced no JSON.');
        }

        return $decoded + ['available' => true];
    }

    /**
     * The findings a 0L would actually be about, newest concerns first.
     *
     * @return array<string, mixed>
     */
    public function summary(bool $strict = false): array
    {
        $report = $this->run($strict);

        if (($report['available'] ?? false) !== true) {
            return $report;
        }

        /** @var list<array<string, mixed>> $findings */
        $findings = $report['findings'] ?? [];

        $errors = array_values(array_filter($findings, fn (array $f): bool => ($f['severity'] ?? '') === 'error'));
        $warnings = array_values(array_filter($findings, fn (array $f): bool => ($f['severity'] ?? '') === 'warning'));

        /** @var array<string, mixed> $claims */
        $claims = $report['claims'] ?? [];

        return [
            'available' => true,
            'ok' => $report['ok'] ?? false,
            'repos' => $report['repos'] ?? [],
            'claims' => $claims,
            // Named rather than buried. A run that could not resolve a third of
            // its claims because a repo was absent must not read to an agent as
            // full coverage — that is the same mistake as a green conformance
            // run over two ports that are identically wrong.
            'unresolved' => $claims['unresolvable'] ?? 0,
            'stale' => array_values(array_filter(
                $report['staleness'] ?? [],
                fn (array $row): bool => ($row['state'] ?? '') !== 'current',
            )),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $why): array
    {
        return ['available' => false, 'ok' => false, 'reason' => $why, 'findings' => []];
    }
}
