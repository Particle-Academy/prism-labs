<?php

declare(strict_types=1);

namespace Tests\Concerns;

use RuntimeException;

/** Test-only native process boundary: never accept an operational database. */
final class NativeAuthorityFixture
{
    public static function waitForCapObservation(string $directory, string $marker): void
    {
        self::directory($directory);
        if (! in_array($marker, ['cap-first-read', 'cap-writer-requested', 'cap-first-finished'], true)) {
            throw new RuntimeException('Unknown native cap observation.');
        }
        $deadline = microtime(true) + 5;
        do {
            if (is_file($directory.'/'.$marker)) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Native cap peer did not reach its observation.');
    }

    public static function directory(string $candidate): string
    {
        $directory = realpath($candidate);
        $parent = realpath(base_path('storage/framework/testing'));
        if ($directory === false || $parent === false || is_link($candidate)
            || dirname($directory) !== $parent
            || preg_match('/^native-authority-[a-f0-9]{16}$/D', basename($directory)) !== 1
            || @file_get_contents($directory.'/fixture.marker') !== "G246 native authority fixture v1\n"
            || getenv('G246_NATIVE_AUTHORITY_DIRECTORY') !== $directory
            || ! self::databaseMatches($directory)
            || getenv('APP_CONFIG_CACHE') !== 'storage/framework/testing/'.basename($directory).'/config.php'
            || ! is_file($directory.'/fixture.env') || filesize($directory.'/fixture.env') !== 0) {
            throw new RuntimeException('Refuse a non-owned native authority fixture.');
        }

        return $directory;
    }

    public static function databaseMatches(string $directory): bool
    {
        return (getenv('DB_CONNECTION') === 'sqlite' && getenv('DB_DATABASE') === ':memory:'
            && getenv('LAB_DB_DATABASE') === $directory.'/lab.sqlite')
            || NativePostgresClaim::owns($directory);
    }

    public static function sdkCalls(): int
    {
        $count = 0;
        app('native-authority-sdk')->assertRequest(function (array $requests) use (&$count): void {
            $count = count($requests);
        });

        return $count;
    }

    public static function barrier(string $directory, string $operation): void
    {
        self::directory($directory);
        if (! in_array($operation, ['launch', 'host', 'prompt', 'cap', 'work'], true)) {
            throw new RuntimeException('Unknown native authority barrier.');
        }
        $ready = $directory.'/barrier-'.$operation.'-'.getmypid();
        $file = fopen($ready, 'x');
        if ($file === false) {
            throw new RuntimeException('Native authority process entered twice.');
        }
        fclose($file);
        $deadline = microtime(true) + 10;
        do {
            if (count(glob($directory.'/barrier-'.$operation.'-*')) === 2) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Native authority peer did not reach its barrier.');
    }

    /** Native CLI exit is an instrument result, not an admission verdict. */
    public static function cli(string $case): array
    {
        return match ($case) {
            'team' => ['action' => 'team.ask', 'input' => ['question' => 'Owned native CLI question'],
                'argv' => ['team:ask', 'Owned native CLI question'], 'changed' => ['team:ask', 'Changed native CLI question'], 'exit' => 0],
            'tests' => ['action' => 'cli.tests.run', 'input' => ['cases' => ['anthropic.text'], 'include_costly' => false],
                'argv' => ['lab:test', 'anthropic.text'], 'changed' => ['lab:test', 'openai.text'], 'exit' => 0],
            'reservation' => ['action' => 'cli.compaction-probe',
                'input' => ['model' => 'claude-sonnet-5', 'trigger' => 5000, 'keep' => 3, 'steps' => 24, 'control' => true],
                'argv' => ['compaction:probe', '--control'], 'changed' => ['compaction:probe', '--control', '--model=unreviewed'], 'exit' => 1],
            'recall' => ['action' => 'cli.compaction-recall',
                'input' => ['model' => 'claude-sonnet-5', 'keep' => 6, 'padding' => 8, 'summarise_with' => null, 'summary_words' => 200],
                'argv' => ['compaction:recall'], 'changed' => ['compaction:recall', '--model=unreviewed'], 'exit' => 1],
            'summary' => ['action' => 'cli.compaction-summary-loss',
                'input' => ['model' => 'claude-sonnet-5', 'summarise_with' => 'claude-haiku-4-5-20251001', 'keep' => 6, 'padding' => 8, 'summary_words' => 60],
                'argv' => ['compaction:summary-loss'], 'changed' => ['compaction:summary-loss', '--model=unreviewed'], 'exit' => 1],
            default => throw new RuntimeException('Unknown native CLI fixture.'),
        };
    }
}
