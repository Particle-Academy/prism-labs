<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Benchmarks\BenchmarkDesigner;
use App\Models\BenchmarkSpec;
use Illuminate\Console\Command;

/**
 * Re-draft an approved spec with corrected lane model ids.
 *
 * A spec is FROZEN and DIGESTED — `ProofRecorder` compares the digest a lane
 * reports against the one stored, so editing `lane_matrix` in place would
 * either break every proof or, worse, quietly pass with a digest that no
 * longer describes the spec that ran. The supported move is a new REVISION,
 * which is what the designer already does; this command is the narrow path for
 * the one thing that goes stale on its own.
 *
 * Model ids retire. When they do, the lane does not fail because the language
 * lost — it fails because the provider has never heard of the model, and the
 * run says nothing about anything. That is a configuration fault wearing a
 * result's clothes, and it is worth its own command so the fix is a revision
 * with an audit trail rather than an UPDATE nobody can see afterwards.
 */
final class BenchmarkRespecCommand extends Command
{
    protected $signature = 'benchmark:respec
        {name : The spec name, matched exactly}
        {--from= : Replace lanes carrying this model id (default: any id the checker flags)}
        {--to= : The model id to use instead}
        {--approve : Approve the new revision immediately}';

    protected $description = 'Re-draft a benchmark spec as a new revision with corrected lane model ids';

    public function handle(BenchmarkDesigner $designer): int
    {
        $spec = BenchmarkSpec::query()->where('name', $this->argument('name'))->orderByDesc('revision')->first();

        if (! $spec instanceof BenchmarkSpec) {
            $this->error(sprintf('No spec named [%s].', $this->argument('name')));

            return self::FAILURE;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        if (! is_string($to) || trim($to) === '') {
            $this->error('--to is required: name the model id the lanes should use.');

            return self::FAILURE;
        }

        $lanes = array_map(function (array $lane) use ($from, $to): array {
            if (! is_string($from) || $lane['model'] === $from) {
                $lane['model'] = $to;
            }

            return $lane;
        }, $spec->lane_matrix);

        if ($lanes === $spec->lane_matrix) {
            $this->info(sprintf('%s r%d already has the requested lanes. Nothing to do.', $spec->name, $spec->revision));

            return self::SUCCESS;
        }

        $draft = $designer->draft(
            $spec->name,
            $spec->archetype,
            $spec->surface_mode,
            $spec->specification,
            $spec->rubric,
            $lanes,
            $spec->budgets,
        );

        $this->line(sprintf('Drafted %s r%d (was r%d), digest %s', $draft->name, $draft->revision, $spec->revision, substr($draft->digest, 0, 16)));

        foreach ($lanes as $lane) {
            $this->line(sprintf('  %s / %s / %s', $lane['language'], $lane['provider'], $lane['model']));
        }

        if ($this->option('approve')) {
            $designer->approve($designer->requestApproval($draft));
            $this->info(sprintf('%s r%d approved.', $draft->name, $draft->revision));
        }

        return self::SUCCESS;
    }
}
