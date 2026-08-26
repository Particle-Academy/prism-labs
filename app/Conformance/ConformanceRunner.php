<?php

declare(strict_types=1);

namespace App\Conformance;

use App\Team\AgentRoster;
use App\Team\LanguageAgent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs the conformance suite in every addressable language and records what
 * each one said, case by case.
 *
 * Case by case is the whole design. The first cross-language run reported
 * identical totals — 46 pass, 3 skip in both TypeScript and Python — while
 * disagreeing on two cases: one that TypeScript skips because its number type
 * cannot represent the value, and one that Python skips instead. A totals-only
 * record would have shown two green lanes and hidden both.
 */
final class ConformanceRunner
{
    public function __construct(private readonly AgentRoster $roster) {}

    /**
     * @return array{run: ConformanceRun|null, languages: array<string, array<string, mixed>>}
     */
    public function run(): array
    {
        $reports = [];
        $failures = [];

        foreach ($this->roster->addressable() as $agent) {
            $result = (new LanguageAgent($agent))->call('run_conformance');
            $data = $result['data'] ?? null;

            if (($result['ok'] ?? false) !== true || ! is_array($data) || ($data['ok'] ?? false) !== true) {
                $failures[$agent->language] = [
                    'ok' => false,
                    // Whatever the lane actually said. A lane that could not run
                    // is a different thing from a lane that ran and failed, and
                    // flattening the two would make a missing corpus look like
                    // a broken port.
                    'reason' => $data['reason'] ?? ($result['reason'] ?? 'the lane did not answer'),
                    'output' => $data['output'] ?? null,
                ];

                continue;
            }

            $reports[$agent->language] = $this->documents($data['report']);
        }

        if ($reports === []) {
            return ['run' => null, 'languages' => $failures];
        }

        $run = $this->store($reports);

        return [
            'run' => $run,
            'languages' => $failures + array_map(
                fn (array $docs): array => [
                    'ok' => true,
                    'suites' => count($docs),
                    'cases' => array_sum(array_map(fn (array $d): int => count($d['results']), $docs)),
                ],
                $reports,
            ),
        ];
    }

    /**
     * The runner emits one document per suite, or a bare document when the
     * corpus ships exactly one. Normalise rather than branch at every use.
     *
     * @return list<array<string, mixed>>
     */
    private function documents(mixed $report): array
    {
        if (! is_array($report)) {
            return [];
        }

        return array_is_list($report) ? $report : [$report];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $reports
     */
    private function store(array $reports): ConformanceRun
    {
        $first = $reports[array_key_first($reports)][0] ?? [];
        $version = (string) ($first['corpus_version'] ?? 'unknown');
        $digest = (string) ($first['corpus_digest'] ?? 'unknown');

        foreach ($reports as $language => $docs) {
            foreach ($docs as $doc) {
                if (($doc['corpus_digest'] ?? null) !== $digest) {
                    // Refused rather than recorded. Results measured against
                    // different corpora are not comparable, and a run that
                    // silently mixed them would report drift that is really
                    // two different questions.
                    throw new RuntimeException(
                        "the {$language} lane ran against a different corpus (".
                        ($doc['corpus_digest'] ?? 'none').") than {$digest}"
                    );
                }
            }
        }

        return DB::transaction(function () use ($reports, $version, $digest): ConformanceRun {
            $run = ConformanceRun::create([
                'corpus_version' => $version,
                'corpus_digest' => $digest,
            ]);

            $rows = [];

            foreach ($reports as $language => $docs) {
                foreach ($docs as $doc) {
                    foreach ($doc['results'] as $result) {
                        $rows[] = [
                            'conformance_run_id' => $run->id,
                            'language' => (string) $language,
                            'suite' => (string) ($doc['suite'] ?? 'unknown'),
                            'case_id' => (string) ($result['id'] ?? 'unknown'),
                            'status' => (string) ($result['status'] ?? 'unknown'),
                            'reason' => $result['reason'] ?? null,
                        ];
                    }
                }
            }

            ConformanceResult::insert($rows);

            return $run;
        });
    }
}
