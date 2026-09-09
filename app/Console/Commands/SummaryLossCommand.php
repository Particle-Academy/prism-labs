<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Benchmarks\SummaryLossProbe;
use Illuminate\Console\Command;

/**
 * Ask what a summary loses, and whether recall makes the loss survivable.
 *
 * Exits non-zero on every inconclusive verdict, for the same reason
 * {@see CompactionRecallCommand} does: a run where nothing was compacted, or
 * where the control answered too, has demonstrated nothing, and a command that
 * exited zero on it would let a meaningless run gate a release.
 *
 * The one exception is "summary kept the nuance". That is a finding about the
 * summariser rather than a broken run — it held detail its prompt does not
 * promise to hold — so it succeeds, and says plainly that recall was never
 * exercised.
 */
final class SummaryLossCommand extends Command
{
    protected $signature = 'compaction:summary-loss
        {--model=claude-sonnet-5 : The model driving the conversation}
        {--summarise-with=claude-haiku-4-5-20251001 : The model writing the summary}
        {--keep=6 : messages kept verbatim in the window}
        {--padding=8 : filler turns used to push the planted turn out}
        {--summary-words=60 : the summary budget — tighten it to force real loss}';

    protected $description = 'Assert that nuance a summary drops can still be recalled';

    public function handle(SummaryLossProbe $probe): int
    {
        $this->line('');
        $this->line('  WHAT A SUMMARY LOSES — is dropped nuance still reachable?');
        $this->line('  '.str_repeat('-', 74));

        $result = $probe->run(
            model: (string) $this->option('model'),
            summariseWith: (string) $this->option('summarise-with'),
            keep: (int) $this->option('keep'),
            padding: (int) $this->option('padding'),
            summaryWords: (int) $this->option('summary-words'),
        );

        $this->line(sprintf(
            '  summariser: %s · budget: %d words',
            $result['summariser'],
            $result['summary_words'],
        ));

        $this->render('with recall', $result['with_recall']);
        $this->render('WITHOUT recall (control)', $result['without_recall']);

        $this->line('');

        if ($result['verdict'] === 'summary loss is survivable — recall recovered what the summary dropped') {
            $this->info('  SURVIVABLE — the summary dropped the nuance and only the arm that could look it up answered.');

            $this->confabulationNote($result['without_recall']);

            return self::SUCCESS;
        }

        if ($result['verdict'] === 'summary kept the nuance — nothing was lost to recover') {
            $this->info('  SUMMARY KEPT THE NUANCE — it survived compaction in the summary itself.');
            $this->line('  Recall was not exercised. Tighten --summary-words to force real loss.');

            return self::SUCCESS;
        }

        // Called out rather than left as one more inconclusive string, because
        // it is a defect in the STRATEGY and not in the run: requirement 2 of
        // the short-term-memory design is a summary that keeps compacting, and
        // a budget stated in a prompt is a request rather than a bound.
        if (str_starts_with($result['verdict'], 'summary ignored its budget')) {
            $this->error('  '.$result['verdict']);
            $this->line('  The budget IS enforced — by whichever SummaryBudget is bound, RetryOnce by default,');
            $this->line('  which asks once more when over and is explicitly allowed to miss. This run is a miss.');
            $this->line('  Bind TruncateTo to make the bound hard, at the cost of a summary that can be cut mid-thought.');
            $this->line('  Until it holds, this probe cannot ask its question: a summary that did not compress');
            $this->line('  cannot have lost anything, so "kept the nuance" would say nothing about the summariser.');

            return self::FAILURE;
        }

        $this->error('  '.$result['verdict']);

        $this->confabulationNote($result['without_recall']);

        return self::FAILURE;
    }

    /**
     * The control arm's behaviour under loss, which is the finding rather than
     * the verdict.
     *
     * An agent that says "I cannot find it" has degraded safely. One that
     * supplies a confident wrong reason has not, and that is the outcome the
     * whole eviction/recall layer exists to prevent — so it is stated whether
     * the run passed or failed.
     *
     * @param  array<string, mixed>  $control
     */
    private function confabulationNote(array $control): void
    {
        if ($control['confabulated'] === true) {
            $this->warn('  Without recall the agent did not say it could not find the reason — it supplied one.');
        }
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function render(string $label, array $r): void
    {
        $this->line('');
        $this->line("  {$label}");
        $this->line(sprintf('    compactions         %d', $r['compactions']));
        $this->line(sprintf('    evicted rows        %d', $r['evicted_rows']));
        // The three lines that decide whether anything below them means anything.
        $this->line(sprintf('    survived rewrites   %d of %d', $r['survived_rewrites'], $r['compactions']));
        $this->line(sprintf('    summary dropped it  %s', $r['summary_dropped_it'] ? 'yes' : 'NO — nothing was lost'));
        $this->line(sprintf('    nuance was stored   %s', $r['nuance_was_stored'] ? 'yes' : 'NO — recall had nothing to find'));
        $this->line(sprintf('    used recall tool    %s', $r['looked'] ? 'yes' : 'no'));
        $this->line(sprintf('    answered correctly  %s', $r['correct'] ? 'yes' : 'no'));
        $this->line(sprintf('    invented a reason   %s', $r['confabulated'] ? 'YES' : 'no'));
        $this->line(sprintf('    answer              %s', str_replace("\n", ' ', mb_substr((string) $r['answer'], 0, 160))));

        // Printed whole, not truncated. It is the evidence for every line above
        // it — whether the nuance is in there is the finding, and a clipped
        // summary would make the one claim worth checking the one thing a
        // reader cannot check.
        // The trajectory, not just the final size. A single overshoot and a
        // summary accreting on every rewrite are different defects, and only
        // the sequence tells them apart.
        $this->line(sprintf(
            '    summary words       %s',
            implode(' → ', array_map(
                fn (int $n): string => $n < 0 ? '—' : (string) $n,
                $r['summary_lengths'],
            )) ?: 'none written',
        ));

        if ($r['summary'] !== '') {
            $this->line(sprintf(
                '    summary (%d words)  %s',
                str_word_count((string) $r['summary']),
                str_replace("\n", ' ', (string) $r['summary']),
            ));
        }

        if ($r['failure'] !== null) {
            $this->line(sprintf('    failure             %s', $r['failure']));
        }
    }
}
