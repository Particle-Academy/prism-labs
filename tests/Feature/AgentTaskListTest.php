<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\TaskListController;
use App\Lab\LabSession;
use App\Learnings\Learning;
use App\Learnings\Severity;
use App\Tasks\TaskListProbe;
use App\Tasks\TaskProbeLearningRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\TaskCompletionTool;
use Prism\Harness\Tools\ToolRegistry;
use Tests\TestCase;

/**
 * The /lab/tasks surface, and the properties it exists to assert.
 *
 * These run the SAME probe the browser runs, rather than a copy of it. A test
 * that re-implemented the sequence would pass while the page was broken, which
 * is the failure mode this Lab exists to notice in other people's code.
 *
 * Driven through the controller rather than the route, because the Lab
 * registers its routes only in the local environment — the same reason
 * PrismLabChatTest drives its middleware directly.
 */
class AgentTaskListTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_security_property_holds_against_the_real_package(): void
    {
        $report = app(TaskListProbe::class)->run();

        foreach ($report['properties'] as $property) {
            $this->assertTrue(
                $property['holds'],
                sprintf('[%s] failed: %s', $property['id'], $property['error'] ?? $this->firstBrokenStep($property)),
            );
        }

        // Named individually, so a property that is silently DROPPED from the
        // probe fails here rather than passing an empty loop. A board that
        // stopped asking a question would otherwise stay green forever.
        $this->assertSame([
            'lapsed-lease-returns-to-todo',
            'release-refuses-a-non-holder',
            'worker-ids-are-compared-verbatim',
            'completion-tool-is-registered-nowhere',
            'silence-is-not-consent',
            'no-worker-closes-a-neighbours-task',
            'an-authorized-holder-can-close-its-own',
        ], array_column($report['properties'], 'id'));

        $this->assertTrue($report['held']);
    }

    /**
     * The alignment decision, asserted where a live agent cannot be run.
     *
     * The browser lane hands the tool to a real model; this asserts the fact
     * that lane depends on — that nothing in this application offers it,
     * including through the wildcard toolset the chat mode actually asks for.
     */
    public function test_no_agent_in_this_application_is_offered_the_completion_tool(): void
    {
        $session = app(LabSession::class)->resolveScope('lab:tasks-test');
        $tools = app(ToolRegistry::class)->resolve(['*'], $session);

        $this->assertArrayNotHasKey(TaskCompletionTool::NAME, $tools);
        $this->assertSame([], app(ToolRegistry::class)->resolve([TaskCompletionTool::NAME], $session));
    }

    /**
     * The registered-nowhere property is not vacuous, and the ordering in
     * TaskListController::agent() is load-bearing.
     *
     * `ToolRegistry` is a process-wide singleton with no unregister and no
     * per-session scoping, so once the live lane registers `complete_task` the
     * board answers differently for the rest of the request. Asserting that
     * HERE is what stops someone reordering the controller and shipping a
     * board that reports red for something the application never did.
     */
    public function test_registering_the_completion_tool_makes_that_property_fail(): void
    {
        app(ToolRegistry::class)->registerFactory(
            TaskCompletionTool::NAME,
            fn ($session) => TaskCompletionTool::for($session->tasks(), $session, app(ToolAuthorizer::class), 'somebody'),
        );

        $report = app(TaskListProbe::class)->run();
        $property = collect($report['properties'])->firstWhere('id', 'completion-tool-is-registered-nowhere');

        $this->assertFalse($property['holds'], 'The property stayed green with the tool registered, so it asserts nothing.');
        $this->assertFalse($report['held']);
    }

    public function test_the_probe_leaves_no_task_lists_behind(): void
    {
        $probe = app(TaskListProbe::class);
        $report = $probe->run();
        $store = app(PrismHarness::class)->stores()->durable();

        $this->assertNotEmpty($report['lists']);

        foreach ($report['lists'] as $key) {
            $this->assertNotNull($store->get($key), "The probe wrote nothing to [{$key}].");
        }

        $probe->forget($report['lists']);

        foreach ($report['lists'] as $key) {
            $this->assertNull($store->get($key), "The probe left [{$key}] behind.");
        }
    }

    public function test_the_probe_restores_the_clock_it_travelled(): void
    {
        app(TaskListProbe::class)->run();

        // A frozen clock leaking out of the probe would date every later row in
        // the request — including the 0L it is about to file.
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function test_a_green_run_still_files_a_learning(): void
    {
        $response = app(TaskListController::class)->probe(app(TaskListProbe::class), app(TaskProbeLearningRecorder::class));
        $body = $response->getData(true);

        $this->assertTrue($body['held']);
        $this->assertNotNull($body['learning'], 'A passing probe filed nothing. Every probe leaves a learning.');
        $this->assertTrue($body['learning']['filed']);

        $learning = Learning::query()->where('ref', $body['learning']['ref'])->firstOrFail();

        $this->assertSame('prism-lab/tasks', $learning->filed_by);
        $this->assertSame(Severity::Info, $learning->severity);
        $this->assertStringContainsString('Verdict fingerprint', $learning->evidence);
        $this->assertFileExists($learning->path);
    }

    public function test_an_unchanged_verdict_reuses_its_learning_rather_than_filing_a_duplicate(): void
    {
        $recorder = app(TaskProbeLearningRecorder::class);
        $probe = app(TaskListProbe::class);

        $first = $recorder->record($probe->run());
        $second = $recorder->record($probe->run());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first['filed']);
        $this->assertFalse($second['filed'], 'The same verdict filed a second 0L. A channel that repeats itself stops being read.');
        $this->assertSame($first['ref'], $second['ref']);
        $this->assertSame(1, Learning::query()->where('filed_by', 'prism-lab/tasks')->count());
    }

    public function test_a_changed_verdict_files_a_new_learning(): void
    {
        $recorder = app(TaskProbeLearningRecorder::class);
        $report = app(TaskListProbe::class)->run();

        $first = $recorder->record($report);

        // The live lane answering differently is a different fact about the
        // same day, and must not be deduplicated against the first.
        $second = $recorder->record($report, ['ran' => true, 'verdict' => 'inconclusive', 'provider' => 'anthropic', 'model' => 'test', 'calls' => []]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($second['filed']);
        $this->assertNotSame($first['ref'], $second['ref']);
        $this->assertSame(Severity::Notable, Learning::query()->where('ref', $second['ref'])->firstOrFail()->severity);
    }

    /**
     * The page renders without running anything.
     */
    public function test_the_page_does_not_probe_on_load(): void
    {
        $rendered = app(TaskListController::class)->show()->toResponse(
            Request::create('/lab/tasks', server: ['HTTP_X_INERTIA' => 'true']),
        );

        $page = json_decode((string) $rendered->getContent(), true);

        $this->assertSame(200, $rendered->getStatusCode());
        $this->assertSame('Lab/Tasks', $page['component']);
        // Static props only. Anything that had to be probed would be here.
        $this->assertSame(['version', 'packages', 'model', 'provider'], array_keys($page['props']));
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private function firstBrokenStep(array $property): string
    {
        foreach ($property['steps'] as $step) {
            if ($step['ok'] !== true) {
                return $step['did'].' → '.$step['got'];
            }
        }

        return 'no step was marked broken, which is itself a bug in the probe';
    }
}
