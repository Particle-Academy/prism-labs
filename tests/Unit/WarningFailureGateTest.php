<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WarningFailureGateTest extends TestCase
{
    public static function warningKinds(): array
    {
        return [
            'native' => ['test_native_suppressed_warning', 'Invalid php:// URL specified'],
            'user' => ['test_user_suppressed_warning', 'Synthetic suppressed warning gate control.'],
        ];
    }

    #[DataProvider('warningKinds')]
    public function test_a_suppressed_php_warning_fails_the_actual_suite_configuration(string $method, string $message): void
    {
        $repo = dirname(__DIR__, 2);
        $env = array_fill_keys(array_keys(getenv()), false);
        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'PATH', 'PATHEXT'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }
        $process = new Process([PHP_BINARY, $repo.'/vendor/bin/phpunit', '--configuration', $repo.'/phpunit.xml',
            '--display-warnings', '--do-not-record-test-run-history', '--filter', $method,
            $repo.'/tests/fixtures/authority/SuppressedWarningProbeTest.php'], $repo,
            [...$env, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
                'LAB_DB_DATABASE' => ':memory:', 'OTEL_PHP_DETECTORS' => 'none'], timeout: 10);
        $process->run();
        $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString($message, $process->getOutput());
    }
}
