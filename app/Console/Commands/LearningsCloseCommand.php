<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Learnings\LearningBacklog;
use Illuminate\Console\Command;

/**
 * Record that a learning was dealt with, and what was done about it.
 *
 * The note is required rather than optional. A learning closed without a reason
 * is indistinguishable from one deleted, and it destroys the only thing a later
 * reader needs: whether the finding was fixed, deferred on purpose, or judged
 * wrong. "Deliberately not fixing this, because …" is a perfectly good close.
 */
final class LearningsCloseCommand extends Command
{
    protected $signature = 'learnings:close
        {ref : The 0L reference, e.g. 0L-0011}
        {--note= : What was done about it. Required.}';

    protected $description = 'Mark a 0Learning as acted on, with a note saying what was done';

    public function handle(LearningBacklog $backlog): int
    {
        $note = $this->option('note');

        if (! is_string($note) || trim($note) === '') {
            $this->error('--note is required: say what was done, even if what was done is "deferred, because …".');

            return self::FAILURE;
        }

        $closed = $backlog->close((string) $this->argument('ref'), $note);

        if ($closed === null) {
            $this->error(sprintf('No learning found with ref [%s].', $this->argument('ref')));

            return self::FAILURE;
        }

        $this->info(sprintf('%s closed: %s', $closed->ref, $closed->acted_note));

        return self::SUCCESS;
    }
}
