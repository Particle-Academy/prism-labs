<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Benchmarks\CompactionRecallProbe;
use Illuminate\Console\Command;

/**
 * Prove — or fail to prove — that an evicted turn is still reachable.
 *
 * Exits non-zero on anything but "recall works", INCLUDING the inconclusive
 * verdicts. A run where the fact never left the window, or where the control
 * answered too, has demonstrated nothing about recall, and a command that exits
 * zero on it would let a meaningless run gate a release.
 */
final class CompactionRecallCommand extends Command
{
    protected $signature = 'compaction:recall
        {--model=claude-sonnet-5 : The model to drive}
        {--keep=6 : messages kept in the window}
        {--padding=8 : filler turns used to push turn one out}
        {--summarise-with= : swap KeepRecentTurns for SummarisingCompaction, using this model}
        {--summary-words=200 : how many words the summary may use — tighten it to force real loss}';

    protected $description = 'Assert that a fact evicted by compaction can still be recalled';

    public function handle(CompactionRecallProbe $probe): int
    {
        $this->line('');
        $this->line('  COMPACTION vs RECALL — is an evicted turn still reachable?');
        $this->line('  '.str_repeat('-', 74));

        $summariseWith = $this->option('summarise-with');

        $result = $probe->run(
            model: (string) $this->option('model'),
            keep: (int) $this->option('keep'),
            padding: (int) $this->option('padding'),
            summariseWith: is_string($summariseWith) && $summariseWith !== '' ? $summariseWith : null,
            summaryWords: (int) $this->option('summary-words'),
        );

        $this->line(sprintf('  strategy: %s', $result['strategy']));

        $this->render('with recall', $result['with_recall']);
        $this->render('WITHOUT recall (control)', $result['without_recall']);

        $this->line('');

        if ($result['verdict'] === 'recall works') {
            $this->info('  RECALL WORKS — the fact left the window, and only the arm that could look it up answered.');

            return self::SUCCESS;
        }

        // Under a summariser this is a RESULT, not a failure: the summary kept
        // the reference, so recall was never needed on this run. Reported as a
        // success because nothing is broken — but named, so nobody reads it as
        // evidence that the recall layer works.
        if ($result['verdict'] === 'summary carried the fact — recall was not needed') {
            $this->info('  SUMMARY CARRIED THE FACT — it survived compaction in the summary itself.');
            $this->line('  Recall was not exercised. This says the summariser kept the detail, not that recall works.');

            return self::SUCCESS;
        }

        $this->error('  '.$result['verdict']);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function render(string $label, array $r): void
    {
        $this->line('');
        $this->line("  {$label}");
        $this->line(sprintf('    evicted rows        %d', $r['evicted_rows']));
        // The line that decides whether anything below it means anything.
        $this->line(sprintf('    fact left window    %s', $r['secret_was_evicted'] ? 'yes' : 'NO — nothing is proven'));
        $this->line(sprintf('    used recall tool    %s', $r['looked'] ? 'yes' : 'no'));
        $this->line(sprintf('    answered correctly  %s', $r['correct'] ? 'yes' : 'no'));
        $this->line(sprintf('    answer              %s', str_replace("\n", ' ', mb_substr((string) $r['answer'], 0, 120))));

        if ($r['failure'] !== null) {
            $this->line(sprintf('    failure             %s', $r['failure']));
        }
    }
}
