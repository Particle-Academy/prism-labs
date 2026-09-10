<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\LabSession;
use App\Models\BenchmarkSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

final class AgentConversationController extends Controller
{
    public function show(Request $request, LabSession $sessions): JsonResponse
    {
        $session = $sessions->resolve($request);

        return response()->json([
            'messages' => $this->messages($session->thread()->messages()),
            'run' => $session->run(),
            'drafts' => $this->drafts(),
        ]);
    }

    /**
     * `/clear` — start a new conversation with the Overseer.
     *
     * RETIRES the thread rather than deleting it, which is the whole reason
     * this calls `newConversation()` instead of emptying a table. The operator
     * asked to clear their context, not to erase the record: every message of
     * the old conversation stays readable at `/lab/threads`, and only one of
     * those two things is recoverable if they meant the other.
     *
     * The session's mode, provider and model are untouched. Clearing a chat
     * should not quietly move the agent to a different model than the one the
     * operator chose on the Models screen.
     */
    public function clear(Request $request, LabSession $sessions): JsonResponse
    {
        $retired = $sessions->resolve($request)->thread();
        $fresh = $sessions->resolve($request)->newConversation();

        return response()->json([
            'messages' => [],
            'run' => null,
            'drafts' => $this->drafts(),
            'cleared' => [
                'retired_thread' => $retired->getKey(),
                'messages_kept' => $retired->storedMessages()->count(),
                'new_thread' => $fresh->getKey(),
            ],
        ]);
    }

    /**
     * One spoken turn: hear it, answer it, hand the answer back as audio.
     *
     * PRESS-TO-TALK. One utterance in, one answer out. Not a live duplex
     * stream — see {@see VoiceExchange}, which is where the reasoning for that
     * lives and which this endpoint is a thin browser-facing wrapper around.
     *
     * ## Inline, like `send()`, and that is a decision rather than a copy
     *
     * The Lab is served single-threaded, so a long request blocks every other
     * one — which is why every PROBE is queued. A voice turn is longer than a
     * text turn (a model turn plus two audio calls), so the same reasoning
     * looks like it should apply.
     *
     * It does not, because of who is waiting. A probe runs for minutes while
     * the operator does something else, and blocking the site for that is
     * indefensible. A voice turn is a FOREGROUND interaction: the one local
     * operator this Lab has is sitting still, holding a microphone, unable to
     * proceed until their own turn finishes. Queuing it would buy nothing they
     * could use and would cost a delivery mechanism for the audio — polling or
     * broadcast — built for a request the user is already blocked on.
     *
     * If the Lab ever has concurrent operators, this is one of the places to
     * revisit. `LabSession` says plainly that it does not.
     */
    public function voice(Request $request, LabSession $sessions, VoiceExchange $voice): JsonResponse
    {
        $input = $request->validate([
            // Base64 rather than a multipart upload because the recorder hands
            // back a Blob and this keeps the request shape identical to every
            // other call the Overseer makes.
            //
            // THE CEILING IS NOT EXPECTED TO BE REACHED. A press-to-talk
            // utterance is seconds; at the ~24-32 kbps WebM/Opus a browser
            // produces, 2 MB of base64 is several minutes. It is here to bound
            // a request body and a transcription bill against a stuck recorder
            // or a pasted payload, not to express a supported recording length.
            'audio' => ['required', 'string', 'max:2097152'],
            // Whisper infers the format from the filename, which Prism derives
            // from this. A wrong or absent type is a confusing provider-side
            // failure, so it is checked here where the message can say so.
            'mime' => ['required', 'string', 'in:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-wav,audio/m4a'],
        ]);

        $session = $sessions->resolve($request)
            ->usingProvider((string) config('team.coordinator.provider'))
            ->usingModel((string) config('team.coordinator.model'))
            ->usingMode('chat');

        try {
            $reply = $voice->exchange($session, Audio::fromBase64($input['audio'], $input['mime']));

            return response()->json([
                ...$reply->toArray(),
                'run' => $session->run(),
                'drafts' => $this->drafts(),
            ]);
        } catch (Throwable $failure) {
            report($failure);

            return response()->json([
                'message' => 'The Overseer could not complete that spoken turn. Your conversation is preserved; try again when the provider is available.',
            ], 503);
        }
    }

    public function send(Request $request, LabSession $sessions): JsonResponse
    {
        $input = $request->validate(['message' => ['required', 'string', 'max:30000']]);
        $session = $sessions->resolve($request)
            ->usingProvider((string) config('team.coordinator.provider'))
            ->usingModel((string) config('team.coordinator.model'))
            ->usingMode('chat');

        try {
            $result = $session->send($input['message']);

            return response()->json([
                'message' => ['id' => $result->runId, 'role' => 'assistant', 'content' => $result->text()],
                'run' => $session->run(),
                'drafts' => $this->drafts(),
            ]);
        } catch (Throwable $failure) {
            report($failure);

            return response()->json([
                'message' => 'The Overseer could not complete that turn. Your conversation is preserved; try again when the provider is available.',
            ], 503);
        }
    }

    /** @param iterable<object> $messages
     * @return list<array{id:string,role:string,content:string}>
     */
    private function messages(iterable $messages): array
    {
        $visible = [];
        foreach ($messages as $index => $message) {
            if ($message instanceof UserMessage || $message instanceof AssistantMessage) {
                $content = trim($message->content);
                if ($content !== '') {
                    $visible[] = ['id' => 'history-'.$index, 'role' => $message instanceof UserMessage ? 'user' : 'assistant', 'content' => $content];
                }
            }
        }

        return array_slice($visible, -100);
    }

    /** @return list<array<string, mixed>> */
    private function drafts(): array
    {
        return BenchmarkSpec::query()->latest()->limit(6)->get(['id', 'name', 'revision', 'status', 'digest', 'archetype', 'surface_mode'])->toArray();
    }
}
