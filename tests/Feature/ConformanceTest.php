<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Conformance\ConformanceResult;
use App\Conformance\ConformanceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: string|null}>  $rows
     */
    private function makeRun(array $rows): ConformanceRun
    {
        $run = ConformanceRun::create([
            'corpus_version' => '0.1.0',
            'corpus_digest' => 'sha256:abc',
        ]);

        foreach ($rows as $row) {
            ConformanceResult::create([
                'conformance_run_id' => $run->id,
                'language' => $row[0],
                'suite' => $row[1],
                'case_id' => $row[2],
                'status' => $row[3],
                'reason' => $row[4] ?? null,
            ]);
        }

        return $run;
    }

    public function test_it_finds_a_disagreement_that_totals_hide(): void
    {
        // The case this whole design exists for. Both languages report one pass
        // and one skip — identical totals — while disagreeing on which case is
        // which. A totals-only view shows two green lanes.
        $run = $this->makeRun([
            ['ts', 'json-container-identity', 'jci-0001', 'pass'],
            ['ts', 'json-container-identity', 'jci-0002', 'skip', 'JavaScript cannot represent 9007199254740993'],
            ['py', 'json-container-identity', 'jci-0001', 'skip', 'Python renders 1.0 where others render 1'],
            ['py', 'json-container-identity', 'jci-0002', 'pass'],
        ]);

        $totals = $run->results->groupBy('language')->map(
            fn ($rows) => $rows->groupBy('status')->map->count()->all()
        )->all();

        // Sorted before comparing: the two languages built these in different
        // insertion order, and assertSame compares key order too. What matters
        // is that the COUNTS match — that is the premise of the test.
        ksort($totals['ts']);
        ksort($totals['py']);

        $this->assertSame($totals['ts'], $totals['py'], 'the totals must match for this test to mean anything');

        $disagreements = $run->disagreements();

        $this->assertCount(2, $disagreements);
        $this->assertSame('jci-0001', $disagreements[0]['case_id']);
        $this->assertSame(['ts' => 'pass', 'py' => 'skip'], $disagreements[0]['statuses']);
    }

    public function test_it_reports_no_disagreement_when_the_languages_agree(): void
    {
        $run = $this->makeRun([
            ['ts', 'suite', 'c-1', 'pass'],
            ['py', 'suite', 'c-1', 'pass'],
            ['ts', 'suite', 'c-2', 'skip', 'same limit in both'],
            ['py', 'suite', 'c-2', 'skip', 'same limit in both'],
        ]);

        $this->assertSame([], $run->disagreements());
    }

    public function test_it_carries_the_reason_a_case_was_skipped(): void
    {
        // A skip without a reason is indistinguishable from a case nobody got
        // round to, and the reason is usually the most interesting thing here.
        $run = $this->makeRun([
            ['ts', 'suite', 'c-1', 'skip', 'JavaScript cannot represent 9007199254740993'],
            ['py', 'suite', 'c-1', 'pass'],
        ]);

        $this->assertStringContainsString(
            '9007199254740993',
            (string) $run->disagreements()[0]['reasons']['ts'],
        );
    }

    public function test_a_case_is_only_a_disagreement_within_its_own_suite(): void
    {
        // Two suites can share a case id. Grouping on the id alone would report
        // an invented disagreement between unrelated cases.
        $run = $this->makeRun([
            ['ts', 'suite-a', 'c-1', 'pass'],
            ['py', 'suite-a', 'c-1', 'pass'],
            ['ts', 'suite-b', 'c-1', 'skip', 'different case entirely'],
            ['py', 'suite-b', 'c-1', 'skip', 'different case entirely'],
        ]);

        $this->assertSame([], $run->disagreements());
    }
}
