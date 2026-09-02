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
        {--from= : Replace lanes carrying this model id (default: every lane)}
        {--to= : The model id to use instead}
        {--lanes= : A whole replacement lane matrix as JSON, when more than the model id is wrong}
        {--approve : Approve the new revision immediately}';

    protected $description = 'Re-draft a benchmark spec as a new revision with corrected lane model ids';

    public function handle(BenchmarkDesigner $designer): int
    {
        $spec = BenchmarkSpec::query()->where('name', $this->argument('name'))->orderByDesc('revision')->first();

        if (! $spec instanceof BenchmarkSpec) {
            $this->error(sprintf('No spec named [%s].', $this->argument('name')));

            return self::FAILURE;
        }

        // Two shapes, because two different things go stale.
        //
        // `--to` is the narrow, common case: the lanes are right and one model
        // id retired under them. `--lanes` is for when the matrix itself was
        // wrong — a provider the package does not have, a driver that cannot
        // run, a lane that has to go. Both land as a new revision; neither
        // edits a frozen spec in place.
        $lanes = $this->option('lanes') !== null
            ? $this->parseLanes((string) $this->option('lanes'))
            : $this->swapModel($spec->lane_matrix);

        if ($lanes === null) {
            return self::FAILURE;
        }

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

    /**
     * @param  list<array<string, mixed>>  $matrix
     * @return list<array<string, mixed>>|null
     */
    private function swapModel(array $matrix): ?array
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! is_string($to) || trim($to) === '') {
            $this->error('Pass either --to (a replacement model id) or --lanes (a whole lane matrix as JSON).');

            return null;
        }

        return array_map(function (array $lane) use ($from, $to): array {
            if (! is_string($from) || $lane['model'] === $from) {
                $lane['model'] = $to;
            }

            return $lane;
        }, $matrix);
    }

    /**
     * Every lane is checked here rather than at launch, because a malformed
     * matrix that reaches the designer becomes a FROZEN revision — and a spec
     * cannot be edited once frozen, only superseded.
     *
     * @return list<array<string, mixed>>|null
     */
    private function parseLanes(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || $decoded === [] || ! array_is_list($decoded)) {
            $this->error('--lanes must be a non-empty JSON array of lane objects.');

            return null;
        }

        foreach ($decoded as $index => $lane) {
            foreach (['language', 'harness', 'provider', 'model'] as $key) {
                if (! is_array($lane) || ! is_string($lane[$key] ?? null) || trim($lane[$key]) === '') {
                    $this->error(sprintf('Lane %d is missing a non-empty string `%s`.', $index + 1, $key));

                    return null;
                }
            }
        }

        return $decoded;
    }
}
