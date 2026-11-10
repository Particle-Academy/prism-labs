<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Consensus\ConsensusCoordinator;
use App\Consensus\ConsensusLearningRecorder;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CollectConsensusJob;
use App\Learnings\Learning;
use App\Models\BenchmarkRun;
use App\Models\ConsensusResponse;
use App\Models\ConsensusRun;
use App\Models\User;
use App\Team\AgentRoster;
use App\Team\LanguageAgent;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

#[Group('dogfood')]
final class DogfoodWorkflowTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    private array $answers = [];

    private ?string $abandonDuringCall = null;

    private array $status = ['agent_stale' => false, 'provider_available' => true, 'can_reason' => true, 'port_version' => 'fixture'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
        $this->authorityFixtureOwner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
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
        $this->cacheModelRoster();
        Http::preventStrayRequests();
        $mcp = function (Request $request) {
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
        };
        Http::fake(['http://127.0.0.1:17411/mcp' => $mcp, 'http://127.0.0.1:17412/mcp' => $mcp]);
    }

    private function beginCollection(string $question): ConsensusRun
    {
        return $this->withApprovedLabRun('consensus.collect', ['question' => $question],
            fn () => app(ConsensusCoordinator::class)->begin($question));
    }

    private function collectQuestion(string $question): ConsensusRun
    {
        $run = $this->beginCollection($question);
        auth('web')->logout();
        $this->beginBenchmarkJob('overseer');
        (new CollectConsensusJob($run->id, ['brief' => ''], $run->paid_work_id))->handle(app(ConsensusCoordinator::class));
        $this->actingAs($this->authorityFixtureOwner, 'web');

        return $run->refresh();
    }

    /** Historical recorded rows exercise rendering without claiming a new agent call. */
    private function recordedRun(string $question, array $responses): ConsensusRun
    {
        $run = ConsensusRun::query()->create(['question' => $question, 'evidence_digest' => str_repeat('a', 64), 'status' => 'awaiting_review']);
        foreach ($responses as $response) {
            ConsensusResponse::query()->create(['consensus_run_id' => $run->id, ...$response]);
        }

        return $run;
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

    public function test_approved_consensus_queues_work_but_cannot_pay_an_agent_without_reviewed_transport(): void
    {
        Queue::fake();
        $this->postApprovedLabRun('/lab/consensus', 'consensus.collect', ['question' => 'How is evicted context recovered?', 'evidence' => 'Inspect the recall contract.'])->assertRedirect('/lab/consensus');
        $this->assertOnlyAuthorityReadinessRequests();
        $run = ConsensusRun::query()->sole();
        $this->assertSame('collecting', $run->status);
        Queue::assertPushedOn('overseer', CollectConsensusJob::class);
        $job = Queue::pushed(CollectConsensusJob::class)->sole();
        auth('web')->logout();
        $this->beginBenchmarkJob('overseer');
        $job->handle(app(ConsensusCoordinator::class));
        $this->assertSame('awaiting_review', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->where('status', 'responded')->count());
        $this->assertSame(2, $run->responses()->where('status', 'unavailable')->count());
        $this->assertSame([LanguageAgent::PAID_AUTHORITY_UNAVAILABLE], $run->responses()->pluck('failure_reason')->unique()->values()->all());
        // Replaying a completed delivery spends nothing and adds no votes.
        $job->handle(app(ConsensusCoordinator::class));
        $this->assertOnlyAuthorityReadinessRequests();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $this->post('/lab/consensus/'.$run->id.'/review', ['synthesis' => 'Use recall; retain the correlation objection.'])->assertRedirect('/lab/consensus');
        $this->assertSame('reviewed', $run->refresh()->status);
        $this->assertNotNull($run->learning_ref);
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.runs.0.tally.responded', 0)
            ->assertJsonPath('props.runs.0.tally.unavailable', 2)
            ->assertJsonPath('props.runs.0.synthesis', 'Use recall; retain the correlation objection.');
    }

    public function test_recorded_answers_and_dissent_reach_human_review_without_new_agent_calls(): void
    {
        $run = $this->recordedRun('Recorded recall question', [
            ['agent' => 'Recorded TypeScript', 'language' => 'ts', 'status' => 'responded', 'answer' => 'Use the harness recall contract.', 'evidence' => ['contract'], 'confidence' => 0.7],
            ['agent' => 'Recorded Python', 'language' => 'py', 'status' => 'responded', 'answer' => 'Use the harness recall contract.', 'evidence' => ['contract'], 'confidence' => 0.7,
                'dissent' => 'Shared model ancestry is not independent evidence.'],
        ]);
        $this->post('/lab/consensus/'.$run->id.'/review', ['synthesis' => 'Use recall; retain the correlation objection.'])->assertRedirect('/lab/consensus');
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.runs.0.tally.responded', 2)->assertJsonPath('props.runs.0.tally.dissenting', 1)
            ->assertJsonPath('props.runs.0.synthesis', 'Use recall; retain the correlation objection.');
        $this->assertNotNull($run->refresh()->learning_ref);
        Http::assertNothingSent();
    }

    public function test_recorded_semantic_refusals_and_empty_answers_render_as_diagnostics_never_votes(): void
    {
        $run = $this->recordedRun('Recorded diagnostic question', [
            ['agent' => 'Recorded TypeScript', 'language' => 'ts', 'status' => 'unavailable', 'failure_reason' => 'No ANTHROPIC_API_KEY set for this agent.'],
            ['agent' => 'Recorded Python', 'language' => 'py', 'status' => 'unavailable', 'failure_reason' => 'The agent returned no usable answer.'],
        ]);
        app(ConsensusLearningRecorder::class)->record($run);
        $run->refresh();
        $this->assertSame(0, $run->responses()->where('status', 'responded')->count());
        $this->assertSame('No ANTHROPIC_API_KEY set for this agent.', $run->responses()->where('language', 'ts')->sole()->failure_reason);
        $this->assertNotNull($run->responses()->where('language', 'py')->sole()->failure_reason);
        $this->assertNotNull($run->learning_ref);
        $this->assertStringContainsString('No ANTHROPIC_API_KEY', Learning::query()->where('ref', $run->learning_ref)->sole()->evidence);
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()->assertJsonPath('props.runs.0.tally.responded', 0);
        Http::assertNothingSent();
    }

    public function test_unconfigured_endpoint_keeps_the_actual_failure_on_the_page(): void
    {
        config(['team.endpoints.ts' => '', 'team.endpoints.py' => '']);
        $this->collectQuestion('Can the agents answer?');
        $this->assertOnlyAuthorityReadinessRequests();
        $this->getJson('/lab/consensus', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.runs.0.responses.0.failure_reason', LanguageAgent::PAID_AUTHORITY_UNAVAILABLE);
        $this->getJson('/lab/team/readiness')->assertOk()
            ->assertJsonPath('lanes.0.capabilities.consensus.reason', 'this lane has no endpoint configured');
    }

    public function test_failed_worker_records_a_terminal_outcome_and_cannot_resurrect_abandonment(): void
    {
        $coordinator = app(ConsensusCoordinator::class);
        $run = $this->beginCollection('Can the worker finish?');
        $job = new CollectConsensusJob($run->id, ['brief' => ''], $run->paid_work_id);
        $job->failed(new \RuntimeException('fixture worker timeout'));
        $job->handle($coordinator);
        $this->assertOnlyAuthorityReadinessRequests();
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

    public function test_a_late_bound_delivery_cannot_reopen_an_abandoned_collection(): void
    {
        $coordinator = app(ConsensusCoordinator::class);
        $run = $this->beginCollection('Stop before the delayed worker answers.');
        $coordinator->abandon($run, 'Operator stopped collection before delivery.');
        $this->beginBenchmarkJob('overseer');
        (new CollectConsensusJob($run->id, ['brief' => ''], $run->paid_work_id))->handle($coordinator);
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        $this->assertOnlyAuthorityReadinessRequests();
        $this->assertSame(1, Learning::query()->count());
    }

    public function test_a_stale_form_cannot_overwrite_a_completed_review(): void
    {
        $coordinator = app(ConsensusCoordinator::class);
        $run = $this->recordedRun('Recorded completed collection', [
            ['agent' => 'Recorded TypeScript', 'language' => 'ts', 'status' => 'responded', 'answer' => 'Freeze the verdict.'],
        ]);
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
            $draft = $designer->draft('dogfood-'.$language, 'application', 'standard', ['outcome' => 'Expected before execution', 'acceptance' => ['Receipt proves the outcome']], ['functional' => 100], [['language' => $language, 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']], ['max_turns' => 2, 'cost' => 0.1]);

            return $designer->approve($designer->requestApproval($draft));
        };
        $php = $create('php');
        [$intent] = $this->approvedLabRun('benchmarks.launch', [], $php->id);
        $this->post('/lab/benchmarks/'.$php->id.'/launch', ['approved_run_id' => $intent->id])->assertRedirect();
        $run = BenchmarkRun::query()->sole();
        $this->assertSame('running', $run->status);
        $this->assertNotNull($run->lanes()->sole()->workflow_run_id);
        Queue::assertPushed(AdvanceWorkflowJob::class, 1);
        $ts = $create('typescript');
        [$intent] = $this->approvedLabRun('benchmarks.launch', [], $ts->id);
        $this->post('/lab/benchmarks/'.$ts->id.'/launch', ['approved_run_id' => $intent->id])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, BenchmarkRun::query()->count(), 'A refused port must never become an empty successful run.');
    }
}
