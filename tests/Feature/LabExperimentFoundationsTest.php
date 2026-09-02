<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkFuse;
use App\Benchmarks\BenchmarkRunReconciler;
use App\Benchmarks\BenchmarkWorkflow;
use App\Benchmarks\LaneActivity;
use App\Benchmarks\LaneWorkspace;
use App\Benchmarks\ProofRecorder;
use App\Consensus\ConsensusCoordinator;
use App\Http\Controllers\Lab\AgentConversationController;
use App\Http\Controllers\Lab\BenchmarkController;
use App\Lab\LabSession;
use App\Learnings\Learning;
use App\Models\BenchmarkLane;
use App\Models\ConsensusRun;
use App\Models\LabOperation;
use App\Telemetry\OperationLedger;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Prism\Harness\Flow\HarnessAgentExecutor;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Workspace\Exceptions\PathRefused;
use Tests\TestCase;

final class LabExperimentFoundationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plab_agent_keeps_one_durable_consumer_conversation(): void
    {
        Prism::fake([TextResponseFake::make()
            ->withText('Let us define what evidence would make this benchmark useful.')
            ->withMessages(collect([
                new UserMessage('Help me design a benchmark.'),
                new AssistantMessage('Let us define what evidence would make this benchmark useful.'),
            ]))]);
        $request = Request::create('/lab/agent', 'POST', ['message' => 'Help me design a benchmark.']);

        $response = app(AgentConversationController::class)->send($request, app(LabSession::class));
        $history = app(AgentConversationController::class)->show(Request::create('/lab/agent'), app(LabSession::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('assistant', $response->getData(true)['message']['role']);
        $this->assertSame(['user', 'assistant'], array_column($history->getData(true)['messages'], 'role'));
    }

    public function test_it_runs_on_the_current_durable_fancy_flow_contract(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('vendor/particle-academy/fancy-flow-php/composer.json')), true);

        $this->assertSame('^8.4', $manifest['require']['php']);
        $this->assertContains('FancyFlow\\Contracts\\NodeExecutor', class_implements(HarnessAgentExecutor::class));
        $this->assertSame('per_node', config('fancy-flow.queue.driver'));
        $this->assertTrue(config('fancy-flow.persistence.enabled'));
    }

    public function test_benchmark_specs_are_revisioned_frozen_and_randomized_into_lanes(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $lanes = [
            ['language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet'],
            ['language' => 'ts', 'harness' => 'prism-ts', 'provider' => 'openai', 'model' => 'gpt'],
        ];
        $spec = $designer->draft('todo', 'application', 'human_plus', ['acceptance' => ['loads']], ['working' => 10], $lanes, ['max_turns' => 20]);

        $this->assertSame(1, $spec->revision);
        $this->assertSame(64, strlen($spec->digest));
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));

        $this->assertSame('queued', $run->status);
        $this->assertCount(2, BenchmarkLane::query()->where('benchmark_run_id', $run->id)->get());
    }

    public function test_benchmark_studio_contract_freezes_and_launches_a_real_three_lane_run(): void
    {
        $lanes = array_map(fn (array $lane): array => [...$lane, 'provider' => 'anthropic', 'model' => 'sonnet'], [
            ['language' => 'php', 'harness' => 'prism-harness'],
            ['language' => 'typescript', 'harness' => 'prism-ts-harness'],
            ['language' => 'python', 'harness' => 'prism-py-harness'],
        ]);
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('Human+ planner', 'typescript-sqlite-fancy', 'human_plus', [
            'outcome' => 'Build a collaborative planner.', 'acceptance' => ['Persists to SQLite', 'Exposes stable handles'],
        ], ['functional' => 40], $lanes, ['cost' => 15]);
        $this->assertSame('draft', $spec->status);
        $this->assertCount(3, $spec->lane_matrix);

        Queue::fake();
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        app(BenchmarkWorkflow::class)->dispatch($run);

        $this->assertDatabaseCount('benchmark_runs', 1);
        $this->assertDatabaseCount('benchmark_lanes', 3);
        $this->assertDatabaseCount((new WorkflowRun)->getTable(), 3);
        $this->assertNotNull($run->refresh()->workflow_run_id);
        $this->assertSame(3, BenchmarkLane::query()->whereNotNull('workflow_run_id')->count());
        Queue::assertPushed(AdvanceWorkflowJob::class, 3);
    }

    public function test_the_benchmark_fuse_is_terminal_and_idempotent(): void
    {
        Queue::fake();
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('fuse', 'application', 'standard', ['acceptance' => ['stops']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        app(BenchmarkWorkflow::class)->dispatch($run);

        $fuse = app(BenchmarkFuse::class);
        $fuse->trip($run);
        $fuse->trip($run->refresh());

        $this->assertDatabaseHas('benchmark_runs', ['id' => $run->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('benchmark_lanes', ['benchmark_run_id' => $run->id, 'status' => 'cancelled']);
        $this->assertSame(WorkflowRun::FAILED, WorkflowRun::query()->firstOrFail()->status);
        $this->assertNotNull($run->refresh()->cancelled_at);
    }

    public function test_a_run_settles_when_every_lane_reaches_a_terminal_state(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('settlement', 'application', 'standard', ['acceptance' => ['settles']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $run->lanes()->update(['status' => 'failed', 'finished_at' => now()]);

        app(BenchmarkRunReconciler::class)->reconcile($run);

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_every_terminal_run_files_a_learning_including_one_where_every_lane_failed(): void
    {
        // The requirement, stated as a test: a benchmark that produced nothing
        // is the one most worth writing down. The spend and the elapsed time
        // are gone by the time it fails, so an unrecorded total failure means
        // the next operator pays the same cost to reach the same dead end.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('total failure', 'application', 'standard', ['acceptance' => ['learns']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $run->lanes()->update(['status' => 'failed', 'finished_at' => now()]);

        app(BenchmarkRunReconciler::class)->reconcile($run);

        $ref = $run->refresh()->learning_ref;
        $this->assertNotNull($ref, 'A run where every lane failed must still leave a 0L behind.');
        $this->assertDatabaseHas('learnings', ['ref' => $ref, 'severity' => 'urgent']);
    }

    public function test_a_run_stopped_by_the_operator_still_files_a_learning(): void
    {
        // The emergency stop used to be the ONE outcome that left nothing at
        // all — no run reconciliation, no receipts, no 0L. It is also the
        // outcome where a human already decided something was wrong, which is
        // exactly the judgement worth keeping.
        Queue::fake();
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('stopped', 'application', 'standard', ['acceptance' => ['stops']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        app(BenchmarkWorkflow::class)->dispatch($run);

        app(BenchmarkFuse::class)->trip($run);

        $this->assertNotNull($run->refresh()->learning_ref, 'An operator stop must still leave a 0L behind.');
    }

    public function test_a_learning_names_a_rejected_model_id_rather_than_the_exception_class(): void
    {
        // The failure text lives in the lane ACTIVITY, not in `proof`: an
        // exception path records only `failure_class` ("PrismException" —
        // true and useless), while the sentence that identifies the cause is
        // the activity summary. Reading the proof alone missed the 404 on the
        // very run that motivated the check, so the fix is pinned here.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('dead model', 'application', 'standard', ['acceptance' => ['names it']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'failed', 'proof' => ['failure_class' => 'Prism\\Prism\\Exceptions\\PrismException'], 'finished_at' => now()])->save();
        app(LaneActivity::class)->record($lane, 'agent.exception', 'Anthropic Error [404]: not_found_error - model: claude-sonnet-4-20250514', [], 'error');

        app(BenchmarkRunReconciler::class)->reconcile($run);

        $learning = Learning::query()->where('ref', $run->refresh()->learning_ref)->firstOrFail();
        $this->assertStringContainsString('claude-sonnet-4-20250514', (string) $learning->what_should_change);
        $this->assertStringContainsString('NEW REVISION', (string) $learning->what_should_change);
        $this->assertStringContainsString('not_found_error', (string) $learning->evidence);
    }

    public function test_the_run_room_names_lane_position_rather_than_only_what_trails_it(): void
    {
        // "0 lane(s) remain queued behind it" is true of the last lane of ten
        // and of a single-lane run, and says neither how far along the run is
        // nor how much is left. A two-lane run whose first lane had finished
        // reported exactly that and read as though nothing else existed.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('position', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [
            ['language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-opus-5'],
            ['language' => 'php', 'harness' => 'cli', 'provider' => 'openai', 'model' => 'gpt-4.1-mini'],
        ], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));

        $lanes = $run->lanes()->orderBy('ordinal')->get();
        $lanes[0]->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        $lanes[1]->forceFill(['status' => 'running', 'started_at' => now()])->save();

        // The controller directly: Lab routes only register under `local`.
        $props = app(BenchmarkController::class)->runRoom($run->refresh())->toResponse($this->inertiaRequest())->getData(true)['props'];

        $this->assertStringContainsString('Lane 2 of 2', $props['worker']['message']);
        $this->assertStringContainsString('1 finished', $props['worker']['message']);
        $this->assertStringContainsString('0 still queued', $props['worker']['message']);
    }

    public function test_a_running_lane_reports_when_it_was_last_heard_from(): void
    {
        // Without this the card prints "Agent is working" from the moment a
        // lane is claimed until the moment it fails, so a lane that has gone
        // silent is indistinguishable from one mid-build. Read from BOTH
        // stores: an agent deep in a build emits tool operations for minutes
        // without emitting a narrated activity.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('heartbeat', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [
            ['language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-opus-5'],
        ], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = $run->lanes()->firstOrFail();
        $lane->forceFill(['status' => 'running', 'started_at' => now()->subMinutes(5)])->save();

        // Only a tool operation, deliberately: activities alone would report
        // this busy lane as never having been heard from.
        DB::table('lab_operations')->insert([
            'id' => (string) Str::ulid(), 'benchmark_lane_id' => $lane->id, 'kind' => 'tool.call',
            'status' => 'completed', 'started_at' => now()->subSeconds(20),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $props = app(BenchmarkController::class)->runRoom($run->refresh())->toResponse($this->inertiaRequest())->getData(true)['props'];

        $this->assertNotNull(
            $props['run']['lanes'][0]['last_seen_at'],
            'A running lane must report when it was last heard from, or a stalled lane is indistinguishable from a busy one.',
        );
    }

    /** Inertia only answers with JSON to an Inertia request; without the header it renders HTML. */
    private function inertiaRequest(): Request
    {
        return Request::create('/lab/benchmarks/runs', 'GET', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '']);
    }

    public function test_benchmark_run_data_can_be_purged_by_scope(): void
    {
        Queue::fake();
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('cleanup', 'application', 'standard', ['acceptance' => ['cleans']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $spec = $designer->approve($designer->requestApproval($spec));
        $queued = $designer->launch($spec);
        $settled = $designer->launch($spec);
        app(BenchmarkWorkflow::class)->dispatch($settled);
        app(BenchmarkFuse::class)->trip($settled);

        $this->assertSame(1, app(BenchmarkFuse::class)->clear('queued'));
        $this->assertModelMissing($queued);
        $this->assertSame(1, app(BenchmarkFuse::class)->clear('settled'));
        $this->assertModelMissing($settled);
        $this->assertDatabaseCount('benchmark_lanes', 0);
        $this->assertDatabaseCount((new WorkflowRun)->getTable(), 0);
        $this->assertDatabaseCount('fancy_flow_workflow_run_nodes', 0);
    }

    public function test_lane_inspection_reads_only_bounded_files_from_its_workspace(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('inspect', 'application', 'standard', ['acceptance' => ['visible']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = BenchmarkLane::query()->firstOrFail();
        $workspace = app(LaneWorkspace::class);
        $workspace->provision($lane);
        $workspace->workspace($lane)->write('src/App.tsx', 'export default function App() {}');
        app(LaneActivity::class)->record($lane, 'file.created', 'Created src/App.tsx');

        $this->assertSame('/src/App.tsx', $workspace->read($lane, '/src/App.tsx')['path']);
        $paths = array_column($workspace->files($lane), 'path');
        $this->assertContains('/src/App.tsx', $paths);
        $this->assertNotContains('/.skills/remotion/SKILL.md', $paths);
        $this->assertDatabaseHas('benchmark_lane_activities', ['benchmark_lane_id' => $lane->id, 'kind' => 'file.created']);

        $this->expectException(PathRefused::class);
        try {
            $workspace->read($lane, '../../.env');
            $this->fail('Traversal must be refused.');
        } finally {
            $workspace->workspace($lane)->clear();
        }
    }

    public function test_lane_inspection_returns_only_what_the_caller_does_not_have(): void
    {
        // The 502s. This endpoint re-sent 500 activities, 500 operations and a
        // whole workspace listing every three seconds, and Genie serves this
        // site through ONE php-cgi worker -- so a live run kept the site's
        // entire capacity busy re-sending what the client already had.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('incremental', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = $run->lanes()->firstOrFail();

        $activity = app(LaneActivity::class);
        $activity->record($lane, 'lane.started', 'first');
        $activity->record($lane, 'file.written', 'second');

        $full = app(BenchmarkController::class)->lane(Request::create('/'), $run, $lane, app(LaneWorkspace::class));
        $first = $full->getData(true);

        $this->assertCount(2, $first['activities']);
        $this->assertFalse($first['incremental']);
        $this->assertIsArray($first['files'], 'A first load must carry the workspace listing.');

        // Now ask again as the poller does: cursor set, files not wanted.
        $cursor = max(array_column($first['activities'], 'id'));
        $next = app(BenchmarkController::class)->lane(
            Request::create('/', 'GET', ['since_activity' => $cursor, 'files' => '0']),
            $run, $lane, app(LaneWorkspace::class),
        )->getData(true);

        $this->assertSame([], $next['activities'], 'Nothing new happened, so nothing should come back.');
        $this->assertTrue($next['incremental']);
        $this->assertNull($next['files'], 'An unrequested listing must be null -- never an empty array, which reads as "no files".');

        // And a new row after the cursor does come back, alone.
        $activity->record($lane, 'lane.finished', 'third');
        $after = app(BenchmarkController::class)->lane(
            Request::create('/', 'GET', ['since_activity' => $cursor, 'files' => '0']),
            $run, $lane, app(LaneWorkspace::class),
        )->getData(true);

        $this->assertCount(1, $after['activities']);
        $this->assertSame('third', $after['activities'][0]['summary']);
    }

    public function test_the_operation_cursor_cannot_drop_rows_that_share_a_second(): void
    {
        // `lab_operations.id` is a random UUID v4, so a key-ordered cursor is
        // meaningless -- it would include or drop rows at random. The cursor is
        // `started_at`, and it must be INCLUSIVE: four tool calls landed on the
        // same second in a real run, and an exclusive bound loses three of them.
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('cursor ties', 'application', 'standard', ['acceptance' => ['x']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = $run->lanes()->firstOrFail();

        // Deliberately out of key order and all on one second.
        $moment = now()->startOfSecond();
        foreach (['ffffffff-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', '88888888-0000-4000-8000-000000000003'] as $id) {
            DB::table('lab_operations')->insert([
                'id' => $id, 'benchmark_lane_id' => $lane->id, 'kind' => 'tool.call', 'status' => 'completed',
                'started_at' => $moment, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $payload = app(BenchmarkController::class)->lane(
            Request::create('/', 'GET', ['since_operation' => (string) $moment, 'files' => '0']),
            $run, $lane, app(LaneWorkspace::class),
        )->getData(true);

        $this->assertCount(3, $payload['operations'], 'An inclusive cursor must return every row sharing the boundary second.');
    }

    public function test_lane_inspection_decodes_tool_metadata_for_the_activity_stream(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('tool stream', 'application', 'standard', ['acceptance' => ['visible']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = BenchmarkLane::query()->firstOrFail();
        app(OperationLedger::class)->start('tool.call', [
            'benchmark_run_id' => $run->id,
            'benchmark_lane_id' => $lane->id,
            'metadata' => ['tool_name' => 'workspace_write', 'tool_call_id' => 'tool-1'],
        ]);

        $response = app(BenchmarkController::class)->lane(Request::create('/'), $run, $lane, app(LaneWorkspace::class));

        $this->assertSame('workspace_write', $response->getData(true)['operations'][0]['metadata']['tool_name']);
    }

    public function test_lane_media_is_streamed_inline_from_the_guarded_workspace(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('media inspection', 'video', 'standard', ['acceptance' => ['playable']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = BenchmarkLane::query()->firstOrFail();
        $workspace = app(LaneWorkspace::class);
        $workspace->provision($lane);
        $workspace->workspace($lane)->write('artifacts/demo.mp4', 'bounded-media-fixture');

        $request = Request::create('/lane/media', 'GET', ['path' => '/artifacts/demo.mp4']);
        $response = app(BenchmarkController::class)->laneMedia($request, $run, $lane, $workspace);

        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('inline; filename="demo.mp4"', $response->headers->get('Content-Disposition'));
        $workspace->workspace($lane)->clear();
    }

    public function test_deleting_an_active_run_returns_a_recoverable_message(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('active cleanup', 'application', 'standard', ['acceptance' => ['safe']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $run->forceFill(['status' => 'running'])->save();

        Route::get('/test/run/{run}', fn () => null)->name('lab.benchmark-runs.show');
        Route::getRoutes()->refreshNameLookups();
        $response = app(BenchmarkController::class)->destroy($run, app(BenchmarkFuse::class));

        $this->assertSame(route('lab.benchmark-runs.show', $run), $response->getTargetUrl());
        $this->assertSame('Stop the run before deleting it. The emergency stop is available while the run is active.', session('error'));
        $this->assertModelExists($run);
    }

    public function test_proof_requires_receipts_and_records_digest_bound_evidence(): void
    {
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('proof', 'application', 'standard', ['acceptance' => ['passes']], ['working' => 10], [[
            'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'sonnet',
        ]], ['max_turns' => 10]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = BenchmarkLane::query()->where('benchmark_run_id', $run->id)->firstOrFail();
        $lane->forceFill(['status' => 'running', 'started_at' => now()])->save();

        app(ProofRecorder::class)->complete($lane, [
            'spec_digest' => $spec->digest, 'working_artifact' => 'artifact://local/todo',
            'checks' => ['test' => 'pass'], 'zero_learning' => 'Stable handles were the main friction.',
        ], [['kind' => 'test', 'payload' => ['exit_code' => 0]]], 9.5);

        $this->assertDatabaseHas('benchmark_lanes', ['id' => $lane->id, 'status' => 'completed']);
        $this->assertDatabaseCount('benchmark_receipts', 1);
    }

    public function test_consensus_cannot_be_marked_reviewed_before_collection_finishes(): void
    {
        $run = ConsensusRun::query()->create(['question' => 'Ship?', 'evidence_digest' => str_repeat('a', 64), 'status' => 'collecting']);

        $this->expectException(\LogicException::class);
        app(ConsensusCoordinator::class)->review($run, 'Not yet.');
    }

    public function test_burn_cost_completeness_ignores_non_billable_child_operations(): void
    {
        $ledger = app(OperationLedger::class);
        $child = $ledger->start('tool.call');
        $child->forceFill(['status' => 'completed'])->save();
        LabOperation::query()->create([
            'kind' => 'generation.text', 'status' => 'completed', 'cost_source' => 'provider_reported',
            'input_tokens' => 10, 'output_tokens' => 5, 'cost' => 0.01, 'started_at' => now(),
        ]);

        $burn = $ledger->burn('daily');
        $this->assertSame(1, $burn['operations']);
        $this->assertSame(100.0, $burn['cost_completeness']);
    }
}
