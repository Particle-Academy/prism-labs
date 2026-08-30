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
use App\Models\BenchmarkLane;
use App\Models\ConsensusRun;
use App\Models\LabOperation;
use App\Telemetry\OperationLedger;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

        $response = app(BenchmarkController::class)->lane($run, $lane, app(LaneWorkspace::class));

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
