<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Benchmarks\CompactionReservationProbe;
use App\Models\CompactionProbeRun;
use Illuminate\Console\Command;

/**
 * Run the compaction probe from the CLI, and EXIT NON-ZERO when the guarantee
 * did not hold.
 *
 * The exit code is the point. A probe whose only output is a table is a probe
 * someone reads once; one that fails a command is one that can gate a release.
 * `inconclusive` also exits non-zero, because a run that never exercised the
 * guarantee is not evidence that it holds -- and silently passing on it is the
 * exact failure this whole exercise was built to catch.
 */
final class CompactionProbeCommand extends Command
{
    protected $signature = 'compaction:probe
        {--model=claude-sonnet-5 : The model to drive}
        {--trigger=5000 : input_tokens at which clearing fires}
        {--keep=3 : tool uses retained by each edit}
        {--steps=24 : max agent steps}
        {--control : ALSO run the unreserved positive control}';

    protected $description = 'Assert that a reserved tool stays reserved while the context window is compacted';

    public function handle(CompactionReservationProbe $probe): int
    {
        $this->line('');
        $this->line('  COMPACTION vs RESERVATION — does a Prism guarantee survive the window shrinking?');
        $this->line('  '.str_repeat('-', 76));

        $result = $probe->run(
            model: (string) $this->option('model'),
            maxSteps: (int) $this->option('steps'),
            trigger: (int) $this->option('trigger'),
            keep: (int) $this->option('keep'),
            reserve: true,
        );

        $this->persist($result);
        $this->render('reserved', $result);

        $ok = $result['verdict'] === 'held';

        if ($this->option('control')) {
            // Run SECOND and reported separately. Without it, "the reserved
            // tool never executed" is equally consistent with "the reservation
            // works" and "this prompt never gets the tool called at all".
            $control = $probe->run(
                model: (string) $this->option('model'),
                maxSteps: (int) $this->option('steps'),
                trigger: (int) $this->option('trigger'),
                keep: (int) $this->option('keep'),
                reserve: false,
            );

            $this->persist($control);
            $this->render('control (unreserved)', $control);

            if ($control['verdict'] === 'control-inert') {
                $this->line('');
                $this->error('  The control never executed the tool either — the probe is not exercising anything.');
                $ok = false;
            }
        }

        $this->line('');

        if ($ok) {
            $this->info('  HELD — the reserved tool was requested and never executed, across a compacted window.');

            return self::SUCCESS;
        }

        $this->error('  NOT PROVEN — see the verdict above.');

        return self::FAILURE;
    }

    /**
     * One history, whichever way the probe was started.
     *
     * The Lab page reads this table. A run fired from the CLI that left no row
     * would mean the browser shows a guarantee as unverified while somebody has
     * in fact just verified it — the two surfaces disagreeing about the same
     * fact is worse than either one being empty.
     *
     * @param  array<string, mixed>  $r
     */
    private function persist(array $r): void
    {
        CompactionProbeRun::query()->create([
            'verdict' => $r['verdict'],
            'reserved' => $r['reserved'],
            'model' => $r['model'],
            'steps' => $r['steps'],
            'ledger_reads' => $r['ledger_reads'],
            'attempts' => $r['attempts'],
            'executions' => $r['executions'],
            'tool_uses_cleared' => $r['tool_uses_cleared'],
            'denials' => $r['denials'],
            'duration_ms' => $r['duration_ms'],
            'failure' => $r['failure'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function render(string $label, array $r): void
    {
        $this->line('');
        $this->line("  {$label}");
        $this->line(sprintf('    steps                %d', $r['steps']));
        $this->line(sprintf('    ledger reads         %d', $r['ledger_reads']));
        $this->line(sprintf('    tool uses cleared    %d', $r['tool_uses_cleared']));
        $this->line(sprintf('    confirm ATTEMPTED    %d', $r['attempts']));
        $this->line(sprintf('    confirm EXECUTED     %d', $r['executions']));
        $this->line(sprintf('    verdict              %s', $r['verdict']));

        if ($r['failure'] !== null) {
            $this->line(sprintf('    failure              %s', $r['failure']));
        }
    }
}
