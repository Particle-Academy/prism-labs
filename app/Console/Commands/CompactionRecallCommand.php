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
        {--padding=8 : filler turns used to push turn one out}';

    protected $description = 'Assert that a fact evicted by compaction can still be recalled';

    public function handle(CompactionRecallProbe $probe): int
    {
        $this->line('');
        $this->line('  COMPACTION vs RECALL — is an evicted turn still reachable?');
        $this->line('  '.str_repeat('-', 74));

        $result = $probe->run(
            model: (string) $this->option('model'),
            keep: (int) $this->option('keep'),
            padding: (int) $this->option('padding'),
        );

        $this->render('with recall', $result['with_recall']);
        $this->render('WITHOUT recall (control)', $result['without_recall']);

        $this->line('');

        if ($result['verdict'] === 'recall works') {
            $this->info('  RECALL WORKS — the fact left the window, and only the arm that could look it up answered.');

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
