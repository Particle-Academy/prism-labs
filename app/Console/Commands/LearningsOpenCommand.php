<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Learnings\LearningBacklog;
use App\Learnings\Severity;
use Illuminate\Console\Command;

/**
 * What the Lab has learned that nobody has acted on.
 *
 * Written for an AGENT to read as much as a human: the whole point of a 0L is
 * that the next run should not rediscover it, and that only happens if someone
 * is handed the list and can see what is still outstanding.
 */
final class LearningsOpenCommand extends Command
{
    protected $signature = 'learnings:open
        {--severity= : Only urgent, or notable-and-worse (urgent|notable|info)}
        {--full : Print every section rather than the headline and what should change}';

    protected $description = 'List the 0Learnings nobody has acted on yet, worst first';

    public function handle(LearningBacklog $backlog): int
    {
        $severity = $this->option('severity');
        $floor = is_string($severity) ? Severity::tryFrom($severity) : null;

        if (is_string($severity) && $floor === null) {
            $this->error(sprintf('Unknown severity [%s]. Use urgent, notable or info.', $severity));

            return self::FAILURE;
        }

        $open = $backlog->open($floor);

        if ($open->isEmpty()) {
            $this->info('Nothing open. Every filed learning has been acted on.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d open learning(s), worst first.', $open->count()));
        $this->newLine();

        foreach ($open as $learning) {
            $this->line(sprintf('  <options=bold>%s</> [%s] %s', $learning->ref, $learning->severity->value, $learning->title));
            $this->line(sprintf('    filed by %s%s', $learning->filed_by, $learning->sent_at === null ? '' : ' · already sent to an agent'));

            if ($this->option('full')) {
                $this->line('    what was learned: '.$learning->what_was_learned);
                $this->line('    evidence: '.$learning->evidence);
                $this->line('    why it matters: '.$learning->why_it_matters);
            }

            if (is_string($learning->what_should_change) && $learning->what_should_change !== '') {
                $this->line('    <fg=yellow>what should change:</> '.$learning->what_should_change);
            }

            $this->line('    '.$learning->path);
            $this->newLine();
        }

        $this->comment('Close one with: php artisan learnings:close <ref> --note="what you did"');

        return self::SUCCESS;
    }
}
