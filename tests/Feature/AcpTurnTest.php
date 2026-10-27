<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\AcpController;
use App\Jobs\RunAcpTurnJob;
use App\Models\AcpTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * /lab/acp, end to end: the job really starts `php artisan lab:acp-agent` under prism-acp's containment and speaks ACP
 * to it. Consumer-owned Claude and Codex CLI fixtures speak native frames through the same environment variables
 * a person would set. No model requests or real tools run.
 *
 * The controller is called directly, as the Lab's other tests do: its routes exist only in the local environment.
 */
final class AcpTurnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Release archives intentionally exclude package tests: the consumer owns its fake CLI.
        $fake = base_path('tests/fixtures/acp/fake-claude.php');
        foreach (['LAB_ACP_CLAUDE_BINARY' => PHP_BINARY, 'LAB_ACP_CLAUDE_ARGS' => str_replace('\\', '/', $fake), 'LAB_ACP_MODEL' => 'haiku',
            'LAB_ACP_CODEX_BINARY' => PHP_BINARY, 'LAB_ACP_CODEX_ARGS' => str_replace('\\', '/', base_path('tests/fixtures/acp/fake-codex.php'))] as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach (['LAB_ACP_CLAUDE_BINARY', 'LAB_ACP_CLAUDE_ARGS', 'LAB_ACP_MODEL', 'LAB_ACP_CODEX_BINARY', 'LAB_ACP_CODEX_ARGS', 'LAB_ACP_SESSION_SECONDS'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    public function test_a_turn_runs_through_the_php_agent_and_its_tree_is_gone_afterwards(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('this ACP preview runs providers on Windows only');
        }
        $turn = AcpTurn::query()->create(['status' => AcpTurn::QUEUED, 'prompt' => 'hello', 'model' => 'haiku']);
        (new RunAcpTurnJob($turn->id))->handle();
        $turn->refresh();

        $text = collect($turn->updates)->where('sessionUpdate', 'agent_message_chunk')->map(fn (array $u) => $u['content']['text'])->implode('');
        $this->assertSame(AcpTurn::FINISHED, $turn->status, (string) $turn->error);
        $this->assertSame('end_turn', $turn->stop_reason);
        $this->assertSame('echo: hello', $text);
        $this->assertSame('9adbc891-a558-48e0-93b0-460d6500ead8', $turn->cli_session_id);
        $this->assertSame('Prism Lab ACP agent (PHP)', $turn->handshake['initialize']['agentInfo']['title']);
        $this->assertSame(['default', 'haiku'], array_column($turn->handshake['session/new']['models']['availableModels'], 'modelId'));
        $this->assertSame('job-object', $turn->process['tree']['containment']);
        $this->assertTrue($turn->process['stop']['empty']);
        $this->assertTrue($turn->process['stop']['verified']);
    }

    public function test_continuing_a_conversation_resumes_it_through_session_load(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('this ACP preview runs providers on Windows only');
        }
        $first = AcpTurn::query()->create(['status' => AcpTurn::QUEUED, 'prompt' => 'one']);
        (new RunAcpTurnJob($first->id))->handle();
        Queue::fake();

        $response = app(AcpController::class)->send(Request::create('/lab/acp', 'POST', ['prompt' => 'two', 'continue' => $first->id]));
        $this->assertSame(202, $response->getStatusCode());
        $second = AcpTurn::query()->where('prompt', 'two')->sole();
        $this->assertSame('9adbc891-a558-48e0-93b0-460d6500ead8', $second->resume_session_id);
        Queue::assertPushed(RunAcpTurnJob::class);

        (new RunAcpTurnJob($second->id))->handle();
        $second->refresh();
        $this->assertSame(AcpTurn::FINISHED, $second->status, (string) $second->error);
        $this->assertArrayHasKey('session/load', $second->handshake);
    }

    public function test_a_model_shaped_like_a_flag_never_reaches_the_queue(): void
    {
        Queue::fake();
        try {
            app(AcpController::class)->send(Request::create('/lab/acp', 'POST', ['prompt' => 'x', 'model' => '--dangerously-skip-permissions']));
            $this->fail('the model was accepted');
        } catch (ValidationException $refused) {
            $this->assertArrayHasKey('model', $refused->errors());
        }
        Queue::assertNothingPushed();
    }

    public function test_a_future_tool_is_denied_by_the_lab_through_the_actual_hook(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('this ACP preview runs providers on Windows only');
        }
        $turn = AcpTurn::query()->create(['status' => AcpTurn::QUEUED, 'prompt' => 'hook FutureTool']);
        (new RunAcpTurnJob($turn->id))->handle();
        $turn->refresh();
        $text = collect($turn->updates)->where('sessionUpdate', 'agent_message_chunk')->map(fn (array $update) => $update['content']['text'])->implode('');
        $hook = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AcpTurn::FINISHED, $turn->status, (string) $turn->error);
        $this->assertSame(2, $hook['exit'], 'the real approval adapter must block the tool at the process boundary');
        $this->assertSame('deny', $hook['stdout']['hookSpecificOutput']['permissionDecision']);
        $this->assertTrue($turn->process['stop']['verified']);
        $this->assertSame(0, $turn->process['stop']['survivors']);
    }

    public function test_a_duplicate_delivery_cannot_start_an_already_claimed_turn(): void
    {
        $turn = AcpTurn::query()->create(['status' => AcpTurn::RUNNING, 'prompt' => 'already claimed']);
        (new RunAcpTurnJob($turn->id))->handle();
        $turn->refresh();
        $this->assertSame(AcpTurn::RUNNING, $turn->status);
        $this->assertNull($turn->handshake);
        $this->assertNull($turn->process);
    }

    public function test_codex_runs_the_published_agent_and_keeps_replayed_history_out_of_the_new_answer(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('native ACP process containment is Windows-only');
        }
        $first = AcpTurn::query()->create(['status' => AcpTurn::QUEUED, 'provider' => 'codex', 'prompt' => 'one', 'model' => 'fixture-model']);
        (new RunAcpTurnJob($first->id))->handle();
        $first->refresh();
        $this->assertSame(AcpTurn::FINISHED, $first->status, (string) $first->error);
        $this->assertSame('418fb379-86dc-456a-ac17-ab1df70f7216', $first->cli_session_id);
        $this->assertSame('echo: one', $this->answer($first->updates));
        $this->assertTrue($first->process['stop']['verified']);
        $this->assertSame(0, $first->process['stop']['survivors']);

        Queue::fake();
        $response = app(AcpController::class)->send(Request::create('/lab/acp', 'POST', [
            'provider' => 'codex', 'prompt' => 'two', 'continue' => $first->id, 'model' => 'fixture-model',
        ]));
        $this->assertSame(202, $response->getStatusCode());
        $second = AcpTurn::query()->where('prompt', 'two')->sole();
        putenv('LAB_ACP_CODEX_ARGS='.str_replace('\\', '/', base_path('tests/fixtures/acp/fake-codex.php')).' --fixture-slow-history');
        putenv('LAB_ACP_SESSION_SECONDS=2');
        config(['acp.session_seconds' => 2]);
        (new RunAcpTurnJob($second->id))->handle();
        $second->refresh();
        $this->assertSame(AcpTurn::FINISHED, $second->status, (string) $second->error);
        $this->assertSame('codex', $second->toPayload()['provider']);
        $this->assertSame('echo: two', $this->answer($second->updates));
        $this->assertSame('earlier answer', $this->answer($second->history_updates));
        $this->assertArrayHasKey('session/load', $second->handshake);
        $this->assertTrue($second->process['stop']['empty']);
        $this->assertTrue($second->process['stop']['verified']);
    }

    public function test_native_codex_approval_is_declined(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('native ACP process containment is Windows-only');
        }
        $turn = AcpTurn::query()->create(['status' => AcpTurn::QUEUED, 'provider' => 'codex', 'prompt' => 'approval']);
        (new RunAcpTurnJob($turn->id))->handle();
        $turn->refresh();
        $this->assertSame(AcpTurn::FINISHED, $turn->status, (string) $turn->error);
        $this->assertSame('permission denied', $this->answer($turn->updates));
        $this->assertTrue($turn->process['stop']['verified']);
        $this->assertSame(0, $turn->process['stop']['survivors']);
    }

    public function test_invalid_effective_mcp_configuration_refuses_the_turn_without_leaking_it(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('native ACP process containment is Windows-only');
        }
        putenv('LAB_ACP_CODEX_ARGS='.str_replace('\\', '/', base_path('tests/fixtures/acp/fake-codex.php')).' --fixture-invalid-config');
        $turn = AcpTurn::query()->create(['status' => AcpTurn::QUEUED, 'provider' => 'codex', 'prompt' => 'must refuse']);
        (new RunAcpTurnJob($turn->id))->handle();
        $turn->refresh();
        $this->assertSame(AcpTurn::FAILED, $turn->status);
        $this->assertStringNotContainsString('dummy-sensitive-config', json_encode($turn->toPayload()));
        $this->assertTrue($turn->process['stop']['verified']);
        $this->assertSame(0, $turn->process['stop']['survivors']);
    }

    public function test_cross_provider_or_pending_resumes_never_reach_the_queue(): void
    {
        Queue::fake();
        $previous = AcpTurn::query()->create(['status' => AcpTurn::FINISHED, 'provider' => 'codex', 'prompt' => 'old', 'cli_session_id' => '418fb379-86dc-456a-ac17-ab1df70f7216']);
        $crossed = app(AcpController::class)->send(Request::create('/lab/acp', 'POST', ['provider' => 'claude', 'prompt' => 'x', 'continue' => $previous->id]));
        $this->assertSame(422, $crossed->getStatusCode());
        $previous->update(['status' => AcpTurn::RUNNING]);
        $pending = app(AcpController::class)->send(Request::create('/lab/acp', 'POST', ['provider' => 'codex', 'prompt' => 'x', 'continue' => $previous->id]));
        $this->assertSame(422, $pending->getStatusCode());
        Queue::assertNothingPushed();
    }

    public function test_unknown_provider_is_rejected_and_omitting_it_still_selects_claude(): void
    {
        Queue::fake();
        try {
            app(AcpController::class)->send(Request::create('/lab/acp', 'POST', ['provider' => '--unsafe', 'prompt' => 'x']));
            $this->fail('unknown provider was accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('provider', $error->errors());
        }
        Queue::assertNothingPushed();
        $response = app(AcpController::class)->send(Request::create('/lab/acp', 'POST', ['prompt' => 'default']));
        $this->assertSame('claude', $response->getData(true)['provider']);
        Queue::assertPushed(RunAcpTurnJob::class, 1);
    }

    /** @param list<array<string,mixed>>|null $updates */
    private function answer(?array $updates): string
    {
        return collect($updates)->where('sessionUpdate', 'agent_message_chunk')->map(fn (array $update) => $update['content']['text'])->implode('');
    }
}
