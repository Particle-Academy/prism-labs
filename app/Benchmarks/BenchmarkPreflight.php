<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkSpec;
use App\Team\AgentRoster;
use App\Team\CapabilityProbe;
use App\Team\LanguageAgent;

final readonly class BenchmarkPreflight
{
    /**
     * How a language is spelled to a person.
     *
     * `ucfirst()` produced "Typescript", which is not the name of the
     * language. A reviewer reading a failure notice is entitled to see the
     * thing spelled the way its own project spells it.
     */
    private const DISPLAY = [
        'typescript' => 'TypeScript',
        'javascript' => 'JavaScript',
        'python' => 'Python',
        'php' => 'PHP',
        'go' => 'Go',
        'rust' => 'Rust',
        'csharp' => 'C#',
    ];

    /** The roster key each spec language maps to. */
    private const ROSTER_KEY = [
        'typescript' => 'ts',
        'python' => 'py',
    ];

    public function __construct(private AgentRoster $roster) {}

    /** @return list<string> */
    public function failures(BenchmarkSpec $spec): array
    {
        $failures = [];

        // GROUPED BY LANGUAGE, not per lane.
        //
        // This used to append one failure per lane, so a spec with three
        // TypeScript lanes showed a reviewer the same sentence three times and
        // read as three separate problems. It also probed the endpoint once per
        // lane — the same network round trip, repeated, to learn the same fact.
        foreach ($this->lanesByLanguage($spec) as $language => $lanes) {
            if ($language === 'php') {
                continue;
            }

            [$probe, $agent] = $this->probe($language);
            if ($probe->isOffered()) {
                continue;
            }

            // No "Benchmark preflight failed" prefix here. The caller adds one
            // (BenchmarkController), and saying it in both places produced
            // "Benchmark preflight failed: Benchmark preflight failed for
            // TypeScript" on screen. A failure sentence should not assume it is
            // the whole notice.
            $failures[] = sprintf(
                '%s (%s): %s%s',
                $this->display($language),
                $this->describeLanes($lanes),
                $probe->explain($this->display($language), 'benchmark'),
                // Naming what it DOES offer turns a dead end into a diagnosis.
                // "It does not offer benchmark" leaves a reviewer guessing
                // whether the agent is broken, misconfigured or simply not
                // built for this yet; the list answers that in one line.
                $probe === CapabilityProbe::NotOffered && $agent instanceof LanguageAgent
                    ? $this->describeOffered($agent)
                    : '',
            );
        }

        return $failures;
    }

    /**
     * @return array<string, list<int>> language => the lane positions using it
     */
    private function lanesByLanguage(BenchmarkSpec $spec): array
    {
        $grouped = [];

        foreach ($spec->lane_matrix as $index => $lane) {
            $language = (string) ($lane['language'] ?? '');
            if ($language === '') {
                continue;
            }

            $grouped[$language][] = $index + 1;
        }

        return $grouped;
    }

    /** @return array{0: CapabilityProbe, 1: LanguageAgent|null} */
    private function probe(string $language): array
    {
        $agent = $this->roster->find(self::ROSTER_KEY[$language] ?? $language);

        if ($agent === null) {
            return [CapabilityProbe::Unregistered, null];
        }

        $client = new LanguageAgent($agent);

        return [$client->probe('benchmark'), $client];
    }

    private function describeOffered(LanguageAgent $agent): string
    {
        $offered = $agent->toolNames();

        return $offered === null || $offered === []
            ? ''
            : ' It offers: '.implode(', ', $offered).'.';
    }

    private function display(string $language): string
    {
        return self::DISPLAY[$language] ?? ucfirst($language);
    }

    /** @param list<int> $lanes */
    private function describeLanes(array $lanes): string
    {
        return count($lanes) === 1
            ? 'lane '.$lanes[0]
            : 'lanes '.implode(', ', $lanes);
    }
}
