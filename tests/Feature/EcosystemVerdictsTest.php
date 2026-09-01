<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Team\EcosystemVerdicts;
use Tests\TestCase;

class EcosystemVerdictsTest extends TestCase
{
    public function test_a_family_is_green_only_when_every_reachable_lane_agrees(): void
    {
        $merged = EcosystemVerdicts::merge([
            $this->lane('ts', ['memory' => [['a check', true]]]),
            $this->lane('py', ['memory' => [['a check', true]]]),
        ]);

        $this->assertSame(['memory' => true], $merged['families']);
        $this->assertTrue($merged['families_green']);
    }

    public function test_one_language_failing_takes_the_family_down(): void
    {
        // The parity failure two languages exist to catch. A merge that took
        // the best of the two would hide it.
        $merged = EcosystemVerdicts::merge([
            $this->lane('ts', ['memory' => [['a check', true]]]),
            $this->lane('py', ['memory' => [['a check', false]]]),
        ]);

        $this->assertSame(['memory' => false], $merged['families']);
        $this->assertFalse($merged['families_green']);
    }

    public function test_a_family_that_fails_takes_the_board_down_without_hiding_the_ones_that_passed(): void
    {
        $merged = EcosystemVerdicts::merge([
            $this->lane('ts', ['memory' => [['a', true]], 'mcp' => [['b', false]]]),
            $this->lane('py', ['memory' => [['a', true]], 'mcp' => [['b', true]]]),
        ]);

        $this->assertSame(['memory' => true, 'mcp' => false], $merged['families']);
        $this->assertFalse($merged['families_green']);
    }

    public function test_nothing_answering_is_not_green(): void
    {
        // No lane answering means nothing was proved, and reporting that as
        // agreement is the worst reading a board can offer.
        $merged = EcosystemVerdicts::merge([
            ['language' => 'ts', 'report' => null],
            ['language' => 'py', 'report' => null],
        ]);

        $this->assertSame([], $merged['families']);
        $this->assertFalse($merged['families_green']);
    }

    public function test_a_check_name_present_in_one_lane_and_absent_from_the_other_is_reported_as_drift(): void
    {
        // This has already happened once for real: a typographic apostrophe in
        // one probe and a straight one in the other rendered as two half-ticked
        // rows, and the panel showed drift that did not exist.
        $merged = EcosystemVerdicts::merge([
            $this->lane('ts', ['memory' => [["it is this package's own error", true]]]),
            $this->lane('py', ['memory' => [['it is this package’s own error', true]]]),
        ]);

        $this->assertCount(2, $merged['name_drift']);
        $this->assertSame(['py'], $merged['name_drift'][0]['missing_from']);
        $this->assertSame(['ts'], $merged['name_drift'][1]['missing_from']);

        // The family still reads green, correctly — both checks passed. Drift
        // is a separate signal from failure, and collapsing the two would make
        // a renamed check look like a broken one.
        $this->assertTrue($merged['families_green']);
    }

    public function test_matching_names_report_no_drift(): void
    {
        $merged = EcosystemVerdicts::merge([
            $this->lane('ts', ['memory' => [['a check', true], ['another', true]]]),
            $this->lane('py', ['memory' => [['a check', true], ['another', true]]]),
        ]);

        $this->assertSame([], $merged['name_drift']);
    }

    public function test_an_unreachable_lane_is_not_counted_as_missing_a_name(): void
    {
        // Otherwise every check would report drift the moment one agent went
        // down, burying the signal under noise on exactly the day it matters.
        $merged = EcosystemVerdicts::merge([
            $this->lane('ts', ['memory' => [['a check', true]]]),
            ['language' => 'py', 'report' => null],
        ]);

        $this->assertSame([], $merged['name_drift']);
        $this->assertTrue($merged['families_green']);
    }

    /**
     * @param  array<string, list<array{0: string, 1: bool}>>  $families
     * @return array{language: string, report: array<string, mixed>}
     */
    private function lane(string $language, array $families): array
    {
        return [
            'language' => $language,
            'report' => [
                'families' => array_map(
                    fn (string $name): array => [
                        'family' => $name,
                        'checks' => array_map(
                            fn (array $check): array => ['step' => $check[0], 'ok' => $check[1]],
                            $families[$name],
                        ),
                    ],
                    array_keys($families),
                ),
            ],
        ];
    }
}
