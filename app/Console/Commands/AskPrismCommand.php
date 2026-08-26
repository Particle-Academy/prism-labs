<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Team\Coordinator;
use Illuminate\Console\Command;

final class AskPrismCommand extends Command
{
    protected $signature = 'team:ask {question : What to ask Prism.php}';

    protected $description = 'Put a question to Prism.php and let it delegate to the language agents';

    public function handle(Coordinator $coordinator): int
    {
        $result = $coordinator->ask((string) $this->argument('question'));

        $this->newLine();
        $this->line($result['text']);
        $this->newLine();

        // Printed even when empty. "It answered without asking anyone" is a
        // meaningful outcome for a coordinator, and one you would want to
        // notice rather than assume.
        $this->comment(sprintf(
            '%d step(s), %d tool call(s): %s',
            $result['steps'],
            count($result['tool_calls']),
            $result['tool_calls'] === []
                ? 'none'
                : implode(', ', array_map(fn (array $c): string => $c['name'], $result['tool_calls'])),
        ));

        $this->comment(sprintf(
            'tokens: %s prompt, %s completion',
            $result['usage']['prompt'] ?? '?',
            $result['usage']['completion'] ?? '?',
        ));

        return self::SUCCESS;
    }
}
