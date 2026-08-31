<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\AgentConversationController;
use App\Lab\LabSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Prism\Harness\Models\Thread;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\TestCase;

/**
 * The PLab Agent's own conversation endpoint.
 *
 * It had no coverage, and it is what the entire chat surface loads from — so
 * when a pending migration left `harness_threads` without the subagent lineage
 * columns, the only thing that reported it was a red banner in the drawer
 * reading "The conversation could not be loaded", with nothing naming the
 * cause. A failing assertion here says which column.
 *
 * Exercised through the CONTROLLER rather than the route, because the Lab
 * registers its routes only in the local environment — the same reason
 * PrismLabChatTest drives EnsurePrismLabIsLocal directly.
 */
class AgentConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_conversation_loads_for_a_fresh_visitor(): void
    {
        $response = app(AgentConversationController::class)
            ->show(Request::create('/lab/agent'), app(LabSession::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['messages', 'run', 'drafts'],
            array_keys((array) $response->getData(true)),
        );
    }

    public function test_the_conversation_loads_once_a_thread_carries_subagent_lineage(): void
    {
        // The columns that broke it. `parent_thread_id`, `root_run_id` and the
        // per-message `run_id` arrived with subagents; a database that had not
        // run that migration answered with a 500 the UI could only render as
        // "the conversation could not be loaded".
        app(AgentConversationController::class)->show(Request::create('/lab/agent'), app(LabSession::class));

        $thread = Thread::query()->firstOrFail();
        $thread->root_run_id = 'run_probe';
        $thread->save();

        $thread->record([new UserMessage('hello')], 'run_probe');

        $response = app(AgentConversationController::class)
            ->show(Request::create('/lab/agent'), app(LabSession::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getData(true)['messages']);
    }
}
