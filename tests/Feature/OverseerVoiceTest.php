<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\AgentConversationController;
use App\Lab\LabSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextResponse as TranscriptResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\TestCase;

/**
 * One spoken turn through the Overseer's endpoint.
 *
 * Driven through the CONTROLLER rather than the route, as every other test of
 * this surface is, because the Lab registers its routes only in the local
 * environment.
 *
 * The behaviour worth pinning is not the happy path. It is that silence does
 * not become a turn, and that what was HEARD reaches the browser separately
 * from what was answered — a right answer to a wrong transcription is a
 * microphone problem, and only `heard` lets anyone tell.
 */
class OverseerVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_spoken_turn_returns_the_transcript_the_answer_and_the_audio(): void
    {
        Prism::fake([
            new TranscriptResponse(text: 'what is the budget?'),
            TextResponseFake::make()->withText('Sixty words.')->withMessages(collect([
                new UserMessage('what is the budget?'),
                new AssistantMessage('Sixty words.'),
            ])),
            new AudioResponse(audio: new GeneratedAudio(base64_encode('spoken'), 'audio/mpeg')),
        ]);

        $payload = $this->speak()->getData(true);

        $this->assertSame('what is the budget?', $payload['heard']);
        $this->assertSame('Sixty words.', $payload['text']);
        $this->assertSame(base64_encode('spoken'), $payload['audio']);
        $this->assertSame('audio/mpeg', $payload['audio_type']);
        $this->assertFalse($payload['empty']);

        // The same trailing keys `send()` returns, so the browser can treat a
        // spoken turn as an ordinary one everywhere except the audio.
        $this->assertArrayHasKey('run', $payload);
        $this->assertArrayHasKey('drafts', $payload);
    }

    public function test_the_transcript_is_recorded_in_the_thread_as_text(): void
    {
        Prism::fake([
            new TranscriptResponse(text: 'what is the budget?'),
            TextResponseFake::make()->withText('Sixty words.')->withMessages(collect([
                new UserMessage('what is the budget?'),
                new AssistantMessage('Sixty words.'),
            ])),
            new AudioResponse(audio: new GeneratedAudio(base64_encode('spoken'), 'audio/mpeg')),
        ]);

        $this->speak();

        $stored = app(LabSession::class)->resolve(Request::create('/lab/agent'))
            ->thread()->storedMessages()->pluck('payload')
            ->map(fn ($payload): string => is_string($payload) ? $payload : (string) json_encode($payload))
            ->implode(' ');

        $this->assertStringContainsString('what is the budget?', $stored);
    }

    public function test_silence_never_becomes_a_turn(): void
    {
        // The harness refuses to spend a turn on an empty transcript, and the
        // endpoint must pass that through rather than inventing a message. A
        // bubble rendered here would be in the transcript and not in the thread.
        $fake = Prism::fake([new TranscriptResponse(text: '   ')]);

        $payload = $this->speak()->getData(true);

        $this->assertTrue($payload['empty']);
        $this->assertSame('', $payload['heard']);
        $this->assertNull($payload['audio']);

        // One call: the transcription. No model turn, no synthesis.
        $fake->assertCallCount(1);

        $this->assertSame([], iterator_to_array(
            app(LabSession::class)->resolve(Request::create('/lab/agent'))->thread()->messages()
        ));
    }

    public function test_a_tool_only_turn_answers_without_audio(): void
    {
        // Legitimate rather than an error: nothing was said aloud, so nothing
        // is synthesised. The browser must not treat the null as a failure.
        Prism::fake([
            new TranscriptResponse(text: 'run the probe'),
            TextResponseFake::make()->withText(''),
        ]);

        $payload = $this->speak()->getData(true);

        $this->assertSame('run the probe', $payload['heard']);
        $this->assertSame('', $payload['text']);
        $this->assertNull($payload['audio']);
        $this->assertFalse($payload['empty']);
    }

    public function test_a_provider_failure_is_reported_rather_than_thrown(): void
    {
        Prism::fake([fn () => throw new \RuntimeException('the provider is down')]);

        $response = $this->speak();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('conversation is preserved', $response->getData(true)['message']);
    }

    public function test_it_refuses_a_container_the_transcriber_cannot_read(): void
    {
        // Checked here so the message names the problem. Left to the provider,
        // an unsupported container fails several layers down as something else.
        $this->expectException(ValidationException::class);

        $this->speak(mime: 'audio/aiff');
    }

    public function test_it_refuses_an_utterance_past_the_ceiling(): void
    {
        // A press-to-talk utterance is seconds. The ceiling bounds a stuck
        // recorder or a pasted payload, not a supported recording length.
        $this->expectException(ValidationException::class);

        $this->speak(audio: str_repeat('A', 2_097_153));
    }

    private function speak(?string $audio = null, string $mime = 'audio/webm'): JsonResponse
    {
        $request = Request::create('/lab/agent/voice', 'POST', [
            'audio' => $audio ?? base64_encode('not really audio'),
            'mime' => $mime,
        ]);

        return (new AgentConversationController)->voice($request, app(LabSession::class), new VoiceExchange);
    }
}
