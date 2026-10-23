<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Consensus\ConsensusCoordinator;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CollectConsensusJob;
use App\Learnings\Learning;
use App\Models\BenchmarkRun;
use App\Models\ConsensusRun;
use App\Team\AgentRoster;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('dogfood')]
final class DogfoodWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $answers = [];

    private ?string $abandonDuringCall = null;

    private array $status = ['agent_stale' => false, 'provider_available' => true, 'can_reason' => true, 'port_version' => 'fixture'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN', 'fixture-csrf');
        // Use the actual asset version when a local build exists. An absent
        // version correctly causes Inertia's 409 reload response after a build.
        $version = (new HandleInertiaRequests)->version(\Illuminate\Http\Request::create('/lab/consensus'));
        if ($version !== null) {
            $this->withHeader('X-Inertia-Version', $version);
        }
        config([
            'team.endpoints.ts' => 'http://127.0.0.1:17411/mcp',
            'team.endpoints.py' => 'http://127.0.0.1:17412/mcp',
            'prism.providers.anthropic.api_key' => 'fixture-only',
            'prism.telemetry.enabled' => false,
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);
        $this->app->forgetInstance(AgentRoster::class);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertContains($request->url(), ['http://127.0.0.1:17411/mcp', 'http://127.0.0.1:17412/mcp'], 'Dogfood regression tests must never call a live provider.');
            $language = str_contains($request->url(), '17411') ? 'ts' : 'py';
            $method = $request['method'];
            $result = match ($method) {
                'server/discover' => ['supportedVersions' => ['2026-07-28'], 'capabilities' => ['tools' => (object) []]],
                'tools/list' => ['tools' => array_map(fn ($name) => ['name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]], ['status', 'consensus'])],
                'tools/call' => $this->toolResult($request['params']['name'], $language),
                default => throw new \LogicException('Unexpected MCP method.'),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
        });
    }

    private function toolResult(string $name, string $language): array
    {
        if ($name === 'consensus' && $this->abandonDuringCall !== null) {
            $runId = $this->abandonDuringCall;
            $this->abandonDuringCall = null;
            app(ConsensusCoordinator::class)->abandon(ConsensusRun::query()->findOrFail($runId), 'Operator stopped collection while the call was pending.');
        }
        $data = $name === 'status' ? $this->status : ($this->answers[$language] ?? ['answer' => 'Use the harness recall contract.', 'evidence' => ['contract'], 'confidence' => 0.7, 'dissent' => $language === 'py' ? 'Shared model ancestry is not independent evidence.' : null]);

        return ['isError' => false, 'structuredContent' => $data, 'content' => [['type' => 'text', 'text' => json_encode($data, JSON_THROW_ON_ERROR)]]];
    }

    public function test_consensus_post_queues_work_then_real_mcp_answers_and_dissent_reach_review(): void
    {
        Queue::fake();
        $this->post('/lab/consensus', ['question' => 'How is evicted context recovered?', 'evidence' => 'Inspect the recall contract.'])->assertRedirect('/lab/consensus');
        Http::assertNothingSent();
        $run = ConsensusRun::query()->sole();
        $this->assertSame('collecting', $run->status);
        Queue::assertPushedOn('overseer', CollectConsensusJob::class);
        $job = Queue::pushed(CollectConsensusJob::class)->sole();
        $job->handle(app(ConsensusCoordinator::class));
        $this->assertSame('awaiting_review', $run->refresh()->status);
        $this->assertSame(2, $run->responses()->where('status', 'responded')->count());
        $this->assertSame(1, $run->responses()->whereNotNull('dissent')->count());
        // Replaying a completed delivery spends nothing and adds no votes.
        $job->handle(app(ConsensusCoordinator::class));
        Http::assertSentCount(2);
        $this->post('/lab/consensus/'.$run->id.'/review', ['synthesis' => 'Use recall; retain the correlation objection.'])->assertRedirect('/lab/consensus');
        $this->assertSame('reviewed', $run->refresh()->status);
        $this->assertNotNull($run->learning_ref);
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.runs.0.tally.responded', 2)
            ->assertJsonPath('props.runs.0.tally.dissenting', 1)
            ->assertJsonPath('props.runs.0.synthesis', 'Use recall; retain the correlation objection.');
    }

    public function test_semantic_refusals_and_empty_answers_are_diagnostics_never_votes(): void
    {
        $this->answers = ['ts' => ['ok' => false, 'reason' => 'No ANTHROPIC_API_KEY set for this agent.'], 'py' => ['answer' => '  ']];
        $run = app(ConsensusCoordinator::class)->collect('Is this aligned?');
        $this->assertSame(0, $run->responses()->where('status', 'responded')->count());
        $this->assertSame('No ANTHROPIC_API_KEY set for this agent.', $run->responses()->where('language', 'ts')->sole()->failure_reason);
        $this->assertNotNull($run->responses()->where('language', 'py')->sole()->failure_reason);
        $this->assertNotNull($run->learning_ref);
        $this->assertStringContainsString('No ANTHROPIC_API_KEY', Learning::query()->where('ref', $run->learning_ref)->sole()->evidence);
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.runs.0.tally.responded', 0);
    }

    public function test_unconfigured_endpoint_keeps_the_actual_failure_on_the_page(): void
    {
        config(['team.endpoints.ts' => '', 'team.endpoints.py' => '']);
        app(ConsensusCoordinator::class)->collect('Can the agents answer?');
        Http::assertNothingSent();
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.runs.0.responses.0.failure_reason', 'this lane has no endpoint configured');
    }

    public function test_failed_worker_records_a_terminal_outcome_and_cannot_resurrect_abandonment(): void
    {
        $coordinator = app(ConsensusCoordinator::class);
        $run = $coordinator->begin('Can the worker finish?');
        $job = new CollectConsensusJob($run->id, []);
        $job->failed(new \RuntimeException('fixture worker timeout'));
        $job->handle($coordinator);
        Http::assertNothingSent();
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertNotNull($run->learning_ref);
        $this->assertSame(1, Learning::query()->count());
    }

    public function test_readiness_checks_configuration_and_tools_without_spending_tokens(): void
    {
        $this->getJson('/lab/team/readiness')->assertOk()
            ->assertJsonPath('lanes.0.capabilities.consensus.ready', true)
            ->assertJsonPath('lanes.0.capabilities.benchmark.ready', false)
            ->assertJsonPath('lanes.1.capabilities.consensus.ready', true);
        Http::assertNotSent(fn (Request $request) => ($request['params']['name'] ?? null) === 'consensus');
        $this->status['agent_stale'] = true;
        $this->getJson('/lab/team/readiness')->assertOk()->assertJsonPath('lanes.0.capabilities.consensus.ready', false);
        $this->status['agent_stale'] = false;
        $this->status['can_reason'] = false;
        $this->getJson('/lab/team/readiness')->assertOk()->assertJsonPath('lanes.0.capabilities.consensus.ready', false);
    }

    public function test_a_late_model_response_cannot_reopen_an_abandoned_collection(): void
    {
        $coordinator = app(ConsensusCoordinator::class);
        $run = $coordinator->begin('Stop while a model is answering.');
        $this->abandonDuringCall = $run->id;
        $coordinator->collectPending($run);
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        Http::assertSentCount(1);
        $this->assertSame(1, Learning::query()->count());
    }

    public function test_a_stale_form_cannot_overwrite_a_completed_review(): void
    {
        $coordinator = app(ConsensusCoordinator::class);
        $run = $coordinator->collect('Freeze the human verdict.');
        $stale = ConsensusRun::query()->findOrFail($run->id);
        $coordinator->review($run, 'The signed conclusion.');
        try {
            $coordinator->abandon($stale, 'Arrived from an older tab.');
            $this->fail('A stale tab must not replace a reviewed run.');
        } catch (\LogicException) {
            $this->assertSame('reviewed', $stale->refresh()->status);
            $this->assertSame('The signed conclusion.', $stale->synthesis);
        }
    }

    public function test_forwarded_loopback_cannot_open_the_readiness_surface_to_a_remote_peer(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->getJson('/lab/team/readiness', ['X-Forwarded-For' => '127.0.0.1'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_php_benchmark_launch_is_a_positive_control_and_unsupported_lanes_still_refuse(): void
    {
        Queue::fake();
        $designer = app(BenchmarkDesigner::class);
        $create = function (string $language) use ($designer) {
            $draft = $designer->draft('dogfood-'.$language, 'application', 'standard', ['outcome' => 'Expected before execution', 'acceptance' => ['Receipt proves the outcome']], ['functional' => 100], [['language' => $language, 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5']], ['max_turns' => 2, 'cost' => 0.1]);

            return $designer->approve($designer->requestApproval($draft));
        };
        $php = $create('php');
        $this->post('/lab/benchmarks/'.$php->id.'/launch')->assertRedirect();
        $run = BenchmarkRun::query()->sole();
        $this->assertSame('running', $run->status);
        $this->assertNotNull($run->lanes()->sole()->workflow_run_id);
        Queue::assertPushed(AdvanceWorkflowJob::class, 1);
        $ts = $create('typescript');
        $this->post('/lab/benchmarks/'.$ts->id.'/launch')->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, BenchmarkRun::query()->count(), 'A refused port must never become an empty successful run.');
    }
}
