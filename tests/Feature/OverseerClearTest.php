<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\AgentConversationController;
use App\Lab\LabSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\TestCase;

/**
 * `/clear` starts a new conversation. It does not delete the old one.
 *
 * Those are two different requests that one command is very often asked to mean
 * at once, and only one of them is recoverable if the operator meant the other.
 */
class OverseerClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_starts_an_empty_conversation(): void
    {
        $sessions = app(LabSession::class);
        $request = Request::create('/lab/agent/clear', 'POST');

        $sessions->resolve($request)->thread()->record([new UserMessage('the old conversation')]);

        $payload = (new AgentConversationController)->clear($request, $sessions)->getData(true);

        $this->assertSame([], $payload['messages']);
        $this->assertSame([], iterator_to_array($sessions->resolve($request)->thread()->messages()));
    }

    public function test_it_keep_s_the_retired_conversation(): void
    {
        $sessions = app(LabSession::class);
        $request = Request::create('/lab/agent/clear', 'POST');

        $before = $sessions->resolve($request)->thread();
        $before->record([new UserMessage('remember this')]);

        $payload = (new AgentConversationController)->clear($request, $sessions)->getData(true);

        // The response says what it kept, so the operator can be told rather
        // than left to trust that "cleared" was not "deleted".
        $this->assertSame($before->getKey(), $payload['cleared']['retired_thread']);
        $this->assertSame(1, $payload['cleared']['messages_kept']);
        $this->assertNotSame($before->getKey(), $payload['cleared']['new_thread']);

        $this->assertNotNull($before->fresh());
        $this->assertSame(1, $before->fresh()->storedMessages()->count());
    }

    public function test_clearing_twice_is_harmless(): void
    {
        $sessions = app(LabSession::class);
        $request = Request::create('/lab/agent/clear', 'POST');

        (new AgentConversationController)->clear($request, $sessions);
        $payload = (new AgentConversationController)->clear($request, $sessions)->getData(true);

        $this->assertSame([], $payload['messages']);
    }
}
