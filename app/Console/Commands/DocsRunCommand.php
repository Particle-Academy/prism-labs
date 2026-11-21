<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DocsLab\DocsLabRunner;
use App\DocsLab\LivePlan;
use App\DocsLab\Scenarios;
use App\Lab\ApprovedCliRun;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\DocsLabRun;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * G-316: run the docs-agent lab's scenarios through a lane, from the CLI.
 *
 * Parity with /lab/docs: the same runner, the same records, the same verdicts.
 * The exit code is the verdict: 0 only when every selected scenario passed.
 *
 *   php artisan lab:docs-run                                  # scripted, all, free
 *   php artisan lab:docs-run --lane=live --judge --approved-run=<GO id>
 *
 * The live lane is PAID and refused without the owner's exact GO for this
 * plan (prepare it at /lab/docs). This command can neither create nor approve
 * one. The only other way to get one is `lab:approve-from-ftq`, which records the
 * owner's answer to a Genie question through the same RunAuthority steps.
 */
final class DocsRunCommand extends Command
{
    protected $signature = 'lab:docs-run
        {--lane=scripted : scripted (free, Prism fakes) or live (paid, owner-approved)}
        {--scenario=all : all, or one of S1 S2 S3 S4 R1 R2}
        {--model='.LivePlan::DEFAULT_MODEL.' : live only: the builder model (anthropic)}
        {--judge : live only: also run the model judge (paid, same approval)}
        {--judge-model= : live only: the judge model; defaults to the builder model}
        {--approved-run= : live only: the owner-approved GO reference}';

    protected $description = 'Run the prism-agent-docs lab scenarios (G-316) and record the verdicts';

    public function handle(DocsLabRunner $runner, Scenarios $scenarios): int
    {
        $lane = (string) $this->option('lane');
        $selection = (string) $this->option('scenario');
        try {
            $scenarios->select($selection);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($lane === 'scripted') {
            return $this->report($runner->scripted($selection));
        }
        if ($lane !== 'live') {
            $this->error('Choose --lane=scripted or --lane=live.');

            return self::FAILURE;
        }

        $input = self::liveInput($selection, (string) $this->option('model'), (bool) $this->option('judge'), $this->option('judge-model'));

        return app(ApprovedCliRun::class)->run($this, 'cli.docs.run', $input, function () use ($runner, $input): int {
            $intent = app(RunAuthority::class)->approvedReference((string) $this->option('approved-run'));
            $scope = app(RunScopeFactory::class)->fromAction('cli.docs.run', 'lab:agent', $input);

            return $this->report($runner->live($input, $intent, $scope));
        });
    }

    /** The exact input the owner approves: the page builds the same four keys. */
    public static function liveInput(string $selection, string $model, bool $judge, mixed $judgeModel): array
    {
        return ['scenario' => $selection, 'model' => $model, 'judge' => $judge,
            'judge_model' => is_string($judgeModel) && $judgeModel !== '' ? $judgeModel : $model];
    }

    private function report(DocsLabRun $run): int
    {
        $this->line('');
        $this->line(sprintf('  docs lab, %s lane, prism-agent-docs %s (%s)', $run->lane, $run->agent['version'] ?? '?', substr((string) ($run->agent['source_digest'] ?? ''), 0, 12)));
        foreach ($run->results as $result) {
            $judge = $result->judge_verdict === null ? '' : ' judge '.$result->judge_verdict;
            $this->{$result->passed ? 'info' : 'error'}(sprintf('  %s %s  outcome %s%s', $result->passed ? 'PASS' : 'FAIL', $result->scenario, $result->outcome ?? 'none', $judge));
            foreach ($result->checks as $check) {
                if (! $check['passed']) {
                    $this->line('       x '.$check['label'].': '.$check['detail']);
                }
            }
            if ($result->error !== null) {
                $this->line('       x '.$result->error);
            }
            if ($result->judge_verdict === 'fail' && $result->judge_reason) {
                $this->line('       judge: '.$result->judge_reason);
            }
        }
        if ($run->cost_microusd !== null) {
            $this->line(sprintf('  spent $%.6f (reserved and settled against the approved cap)', $run->cost_microusd / 1_000_000));
        }
        $this->line(sprintf('  run %s: %s', $run->id, strtoupper($run->status)));

        return $run->passed ? self::SUCCESS : self::FAILURE;
    }
}
