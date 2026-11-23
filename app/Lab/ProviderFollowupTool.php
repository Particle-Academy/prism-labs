<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use RuntimeException;

/**
 * The inert tool the check offers. It is client-executed, so the request loop
 * stops at a call to it; if anything ever invoked it anyway, the run counts
 * that and fails rather than doing work.
 */
final class ProviderFollowupTool extends Tool
{
    private static int $runs = 0;

    public static function inert(): self
    {
        $tool = new self;
        $tool->as('lookup')->for('Look up a short value.')->withStringParameter('query', 'What to look up.');
        $tool->clientExecuted();

        return $tool;
    }

    public static function reset(): void
    {
        self::$runs = 0;
    }

    public static function runs(): int
    {
        return self::$runs;
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        self::$runs++;

        throw new RuntimeException('This tool is never executed.');
    }
}
