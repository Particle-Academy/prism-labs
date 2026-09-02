<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\ModelPolicy;
use App\Lab\ProviderRegistry;
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

    /**
     * Provider names people reach for, mapped to what the package calls them.
     *
     * Edit distance cannot bridge these: `google` and `gemini` share one
     * letter, but one is the company and the other is the product, and a spec
     * author naming the company is making the most reasonable mistake
     * available. This spec did exactly that and the lane would have failed at
     * the API with no hint of the cause.
     */
    private const ALIAS = [
        'google' => 'gemini',
        'googleai' => 'gemini',
        'claude' => 'anthropic',
        'gpt' => 'openai',
        'grok' => 'xai',
        'llama' => 'ollama',
    ];

    /** The roster key each spec language maps to. */
    private const ROSTER_KEY = [
        'typescript' => 'ts',
        'python' => 'py',
    ];

    public function __construct(private AgentRoster $roster, private ProviderRegistry $providers, private ModelPolicy $models) {}

    /** @return list<string> */
    public function failures(BenchmarkSpec $spec): array
    {
        // Credentials FIRST, and before any network probe.
        //
        // A lane whose provider is unknown or unconfigured fails the moment it
        // contacts the API, and it fails looking exactly like a result: the
        // language "lost", when in truth the run never asked it anything. That
        // is the same fault that retired model ids produce, and it is the one
        // the preflight was silent about — it checked whether an AGENT could
        // run the lane and never whether the PROVIDER could answer it.
        $failures = $this->providerFailures($spec);

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
     * Every lane's provider must be one Prism knows AND one this Lab holds a
     * credential for. Checked for php lanes too — unlike the capability probe,
     * which php skips because the Lab drives those in process, a missing API
     * key stops an in-process lane exactly as hard as a remote one.
     *
     * @return list<string>
     */
    private function providerFailures(BenchmarkSpec $spec): array
    {
        $failures = [];
        $byProvider = [];

        foreach ($spec->lane_matrix as $index => $lane) {
            $provider = (string) ($lane['provider'] ?? '');
            if ($provider !== '') {
                $byProvider[$provider][] = $index + 1;
            }
        }

        foreach ($byProvider as $provider => $lanes) {
            $where = $this->describeLanes($lanes);

            if ($this->providers->find($provider) === null) {
                // Naming the near-miss matters more than listing all eighteen:
                // this spec asked for `google`, which is not a Prism provider
                // and never was — the package calls it `gemini`.
                $failures[] = sprintf(
                    '%s (%s): `%s` is not a Prism provider.%s',
                    $provider, $where, $provider, $this->suggest($provider),
                );

                continue;
            }

            if (! $this->providers->isConfigured($provider)) {
                $failures[] = sprintf(
                    '%s (%s): no credential is configured. %s',
                    $provider, $where, $this->providers->setupHint($provider),
                );
            }
        }

        // And the model, which is a SPENDING decision rather than a
        // correctness one. A spec is frozen when it is approved, so a model
        // chosen carelessly at design time is one that every run of that spec
        // pays for until somebody cuts a new revision.
        foreach ($spec->lane_matrix as $index => $lane) {
            $provider = (string) ($lane['provider'] ?? '');
            $model = (string) ($lane['model'] ?? '');

            if ($provider === '' || $model === '' || $this->models->permits($provider, $model)) {
                continue;
            }

            $failures[] = sprintf('%s: %s', $this->describeLanes([$index + 1]), $this->models->refusal($provider, $model));
        }

        return $failures;
    }

    /** The closest provider Prism actually has, when one is obvious. */
    private function suggest(string $provider): string
    {
        if (isset(self::ALIAS[$provider]) && $this->providers->find(self::ALIAS[$provider]) !== null) {
            return ' Prism calls it `'.self::ALIAS[$provider].'`.';
        }

        $known = array_keys($this->providers->all());
        $close = array_values(array_filter($known, fn (string $key): bool => levenshtein($key, $provider) <= 3
            || str_contains($key, $provider)
            || str_contains($provider, $key)));

        return $close === [] ? '' : ' Did you mean `'.implode('` or `', array_slice($close, 0, 2)).'`?';
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
