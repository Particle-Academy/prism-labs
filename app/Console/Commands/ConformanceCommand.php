<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Conformance\ConformanceRunner;
use Illuminate\Console\Command;

final class ConformanceCommand extends Command
{
    protected $signature = 'team:conformance';

    protected $description = 'Run the conformance suite in every language and report where they disagree';

    public function handle(ConformanceRunner $runner): int
    {
        $this->comment('Running conformance in every addressable language — this builds first, so give it a moment.');

        $outcome = $runner->run();

        foreach ($outcome['languages'] as $language => $result) {
            if (($result['ok'] ?? false) === true) {
                $this->line(sprintf('  %-4s %d suite(s), %d case(s)', $language, $result['suites'], $result['cases']));

                continue;
            }

            $this->line(sprintf('  <fg=red>%-4s could not run: %s</>', $language, $result['reason'] ?? 'unknown'));
        }

        $run = $outcome['run'];

        if ($run === null) {
            $this->newLine();
            $this->error('No lane produced a report. Nothing recorded.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("corpus {$run->corpus_version} ({$run->corpus_digest})");

        $disagreements = $run->disagreements();

        if ($disagreements === []) {
            // Said explicitly. "No output" and "the languages agree" are
            // different claims, and only one of them is worth trusting.
            $this->info('The languages agree on every case.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(count($disagreements).' case(s) where the languages disagree:');

        foreach ($disagreements as $case) {
            $verdicts = [];

            foreach ($case['statuses'] as $language => $status) {
                $verdicts[] = "{$language}={$status}";
            }

            $this->line("  {$case['suite']}/{$case['case_id']}  ".implode('  ', $verdicts));

            foreach ($case['reasons'] as $language => $reason) {
                if ($reason !== null && $reason !== '') {
                    $this->line("    <fg=gray>{$language}: {$reason}</>");
                }
            }
        }

        // Not a failure. A disagreement is usually a language telling the truth
        // about a limit it has — the JS number type cannot represent every
        // integer the corpus asks about — and exiting non-zero would train
        // everyone to ignore this.
        return self::SUCCESS;
    }
}
