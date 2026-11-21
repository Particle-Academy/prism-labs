<?php

declare(strict_types=1);

namespace Tests\Feature\DocsLab;

use App\DocsLab\Checks;
use App\DocsLab\DocsLabRunner;
use App\DocsLab\Fixtures;
use App\DocsLab\LaneRun;
use App\DocsLab\Scenarios;
use App\Models\DocsLabResult;
use App\Models\DocsLabRun;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use LastWord\Agent;
use Tests\Concerns\DocsLabDatabase;
use Tests\TestCase;

/** G-316: every lab scenario passes on the free scripted lane, from real files. */
final class ScriptedLaneTest extends TestCase
{
    use DocsLabDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLab();
    }

    public function test_every_scenario_passes_on_the_scripted_lane(): void
    {
        $run = app(DocsLabRunner::class)->scripted('all');

        $failures = $run->results->reject(fn (DocsLabResult $r): bool => $r->passed)
            ->map(fn (DocsLabResult $r): array => [$r->scenario, $r->error, collect($r->checks)->reject(fn ($c) => $c['passed'])->values()->all()])->values()->all();
        $this->assertSame([], $failures);
        $this->assertSame(Scenarios::ORDER, $run->results->pluck('scenario')->all());
        $this->assertSame('passed', $run->status);
        $this->assertTrue($run->passed);
        $this->assertNull($run->intent_id);
        $this->assertNull($run->cost_microusd);
        Http::assertNothingSent();
    }

    public function test_the_lane_writes_and_rereads_real_files_with_the_same_picture_bytes(): void
    {
        $run = app(DocsLabRunner::class)->scripted('S1');
        $result = $run->results->sole();
        $dir = DocsLabRunner::directory($run, 'S1');

        $this->assertFileExists($dir.'/source.docx');
        $this->assertFileExists($dir.'/draft.docx');
        $bytes = (string) file_get_contents($dir.'/draft.docx');
        $this->assertSame(hash('sha256', $bytes), $result->artefacts['draft.docx']['sha256']);
        // Read back independently of the lab's own checks: the header holds
        // the logo and the divider, byte for byte, and the body is empty.
        $read = Agent::read($bytes);
        $fixtures = app(Fixtures::class);
        $this->assertSame(
            ['/page/header' => [$fixtures->pictureHash('logo'), $fixtures->pictureHash('divider')]],
            Checks::picturesByPart($read),
        );
        $this->assertSame([], $read['blocks']);
        $this->assertEquals(0, $read['page']['margins']['header']);
    }

    public function test_each_scenario_records_every_check_and_the_tool_calls_the_agent_saw(): void
    {
        $run = app(DocsLabRunner::class)->scripted('S4');
        $result = $run->results->sole();

        $this->assertSame(count(app(Scenarios::class)->find('S4')->expect), count($result->checks));
        $this->assertSame(['report_limit', 'write_draft'], array_column($result->calls, 'tool'));
        $this->assertTrue($result->calls[1]['ok']);
        $this->assertSame('ready', $result->outcome);
        $this->assertStringStartsWith('The draft is ready. A Word page has one pair of side margins', (string) $result->message);
        $this->assertSame(1, DocsLabRun::query()->count());
    }

    public function test_grading_fails_a_run_that_drafted_nothing(): void
    {
        // A positive control for the grading itself: a lane that produced no
        // result at all must fail S1 on every check that needs a draft.
        $s1 = app(Scenarios::class)->find('S1');
        $checks = app(Checks::class)->evaluate($s1, new LaneRun(null, null, null), null, null);

        $passed = collect($checks)->filter(fn (array $c): bool => $c['passed'])->pluck('check')->values()->all();
        $this->assertSame(['never_problem'], $passed);
    }

    public function test_a_scenario_digest_moves_when_its_document_changes(): void
    {
        $root = storage_path('framework/testing/docs-lab-fixtures-'.bin2hex(random_bytes(6)));
        $copy = static function (string $from, string $to) use (&$copy): void {
            @mkdir($to, 0777, true);
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                is_dir($from.'/'.$entry) ? $copy($from.'/'.$entry, $to.'/'.$entry) : copy($from.'/'.$entry, $to.'/'.$entry);
            }
        };
        $copy(resource_path('docs-lab'), $root);
        try {
            $before = (new Scenarios(new Fixtures($root)))->find('S1')->digest;
            file_put_contents($root.'/documents/header-letterhead.json', str_replace('{page}', 'Page {page}', (string) file_get_contents($root.'/documents/header-letterhead.json')));
            $after = (new Scenarios(new Fixtures($root)))->find('S1')->digest;
            $this->assertNotSame($before, $after);
            $this->assertSame($before, app(Scenarios::class)->find('S1')->digest);
        } finally {
            app(Filesystem::class)->deleteDirectory($root);
        }
    }
}
