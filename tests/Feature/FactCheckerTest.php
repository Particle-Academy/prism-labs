<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrity\FactChecker;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The team's window on whether documentation still agrees with code.
 *
 * The wrapper is thin on purpose — prism-parity owns the checking — so what
 * these pin is the handling around it, which is where a thin wrapper goes
 * wrong: a real finding read as a broken tool, a missing script read as a pass,
 * an unresolved claim read as coverage.
 */
class FactCheckerTest extends TestCase
{
    public function test_it_reports_a_missing_checker_rather_than_pretending_all_is_well(): void
    {
        // prism-parity not checked out beside the app. An agent must be able to
        // tell "nothing is wrong" from "nothing was checked" — passing here
        // would be the checker manufacturing the confidence it exists to earn.
        $report = (new FactChecker(base_path('does/not/exist.mjs')))->summary();

        $this->assertFalse($report['available']);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('not on disk', $report['reason']);
    }

    public function test_a_non_zero_exit_with_findings_is_a_result_not_a_broken_tool(): void
    {
        // The script exits 1 when it finds something. Reading the exit code as
        // the verdict would report every genuine finding as a tool failure,
        // which is how a team learns to ignore a tool.
        Process::fake(['*' => Process::result(
            output: (string) json_encode([
                'contract' => '1.0',
                'ok' => false,
                'repos' => [['name' => 'prism', 'version' => 'v0.114.0']],
                'claims' => ['total' => 10, 'verified' => 9, 'unresolvable' => 1],
                'staleness' => [],
                'findings' => [
                    ['severity' => 'error', 'kind' => 'php-class', 'repo' => 'prism', 'file' => 'README.md', 'line' => 4, 'claim' => 'Prism\\Nope', 'message' => 'No such class.'],
                    ['severity' => 'warning', 'kind' => 'local-link', 'repo' => 'prism', 'file' => 'README.md', 'line' => 9, 'claim' => '/x', 'message' => 'Unresolved.'],
                ],
            ], JSON_THROW_ON_ERROR),
            exitCode: 1,
        )]);

        $report = (new FactChecker(__FILE__))->summary();

        $this->assertTrue($report['available']);
        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['errors']);
        $this->assertCount(1, $report['warnings']);
        $this->assertSame('Prism\\Nope', $report['errors'][0]['claim']);
    }

    public function test_it_surfaces_unresolved_claims_so_a_partial_run_is_not_full_coverage(): void
    {
        // The same shape as a green conformance run over two ports that are
        // identically wrong: the number that matters is the one nobody printed.
        Process::fake(['*' => Process::result(
            output: (string) json_encode([
                'ok' => true,
                'claims' => ['total' => 30, 'verified' => 10, 'unresolvable' => 20],
                'findings' => [],
                'staleness' => [],
                'repos' => [],
            ], JSON_THROW_ON_ERROR),
            exitCode: 0,
        )]);

        $this->assertSame(20, (new FactChecker(__FILE__))->summary()['unresolved']);
    }

    public function test_it_keeps_only_the_staleness_rows_that_are_not_current(): void
    {
        Process::fake(['*' => Process::result(
            output: (string) json_encode([
                'ok' => true,
                'claims' => ['total' => 1],
                'findings' => [],
                'repos' => [],
                'staleness' => [
                    ['repo' => 'prism', 'state' => 'current'],
                    ['repo' => 'prism-mcp', 'state' => 'drifted', 'recorded' => 'v0.1.0', 'actual' => 'v0.2.0'],
                ],
            ], JSON_THROW_ON_ERROR),
            exitCode: 0,
        )]);

        $stale = (new FactChecker(__FILE__))->summary()['stale'];

        $this->assertCount(1, $stale);
        $this->assertSame('prism-mcp', $stale[0]['repo']);
    }

    public function test_unparseable_output_is_reported_as_unavailable(): void
    {
        Process::fake(['*' => Process::result(output: 'node: command not found', exitCode: 127)]);

        $this->assertFalse((new FactChecker(__FILE__))->summary()['available']);
    }
}
