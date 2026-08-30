<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkSpec;
use App\Team\AgentRoster;
use App\Team\LanguageAgent;

final readonly class BenchmarkPreflight
{
    public function __construct(private AgentRoster $roster) {}

    /** @return list<string> */
    public function failures(BenchmarkSpec $spec): array
    {
        $failures = [];
        foreach ($spec->lane_matrix as $lane) {
            $language = match ($lane['language']) {
                'typescript' => 'ts',
                'python' => 'py',
                default => $lane['language'],
            };
            if ($language === 'php') {
                continue;
            }

            $agent = $this->roster->find($language);
            if ($agent === null || ! (new LanguageAgent($agent))->offers('benchmark')) {
                $failures[] = sprintf('%s has no benchmark-capable Harness endpoint. The parity agent is not a Harness.', ucfirst((string) $lane['language']));
            }
        }

        return $failures;
    }
}
