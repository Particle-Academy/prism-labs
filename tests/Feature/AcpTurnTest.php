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
 * to it. The only stand-in is the Claude CLI behind the agent, which is prism-acp's own fake (it speaks the real
 * frames), reached through the same environment variables a person would set.
 *
 * The controller is called directly, as the Lab's other tests do: its routes exist only in the local environment.
 */
final class AcpTurnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('prism-acp contains a provider on Windows only so far');
        }
        $fake = base_path('vendor/particle-academy/prism-acp/tests/fixtures/fake-claude.php');
        foreach (['LAB_ACP_CLAUDE_BINARY' => PHP_BINARY, 'LAB_ACP_CLAUDE_ARGS' => str_replace('\\', '/', $fake), 'LAB_ACP_MODEL' => 'haiku'] as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    protected function tearDown(): void
    {
        foreach (['LAB_ACP_CLAUDE_BINARY', 'LAB_ACP_CLAUDE_ARGS', 'LAB_ACP_MODEL'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    public function test_a_turn_runs_through_the_php_agent_and_its_tree_is_gone_afterwards(): void
    {
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
}
