<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Evals\EvalImportRefused;
use App\Evals\ResultsImporter;
use Illuminate\Console\Command;

/**
 * Import a signed harness-bench results.json (G-393).
 *
 *   php artisan lab:import-eval evals/results/<ftq_id>/results.json
 *
 * Refuses a file whose GitHub attestation does not verify against this
 * repository's harness-bench.yml. Runs no benchmark and calls no provider.
 */
final class ImportEvalCommand extends Command
{
    protected $signature = 'lab:import-eval {path : the results.json harness-bench.yml returned}';

    protected $description = 'Import a signed harness-benchmark results file (G-393)';

    public function handle(ResultsImporter $importer): int
    {
        try {
            $run = $importer->import((string) $this->argument('path'));
        } catch (EvalImportRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Imported %s: %d trials, $%.4f of $%.2f.', $run->ftq_id, $run->trials()->count(), $run->spent_usd, $run->cap_usd));

        return self::SUCCESS;
    }
}
