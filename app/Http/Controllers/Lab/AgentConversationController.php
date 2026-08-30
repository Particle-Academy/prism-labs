<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\LabSession;
use App\Models\BenchmarkSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'message' => 'The PLab Agent could not complete that turn. Your conversation is preserved; try again when the provider is available.',
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
