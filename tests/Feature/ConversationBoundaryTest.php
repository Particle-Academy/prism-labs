<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\AgentConversationController;
use App\Http\Controllers\Lab\ChatController;
use App\Lab\LabConversation;
use App\Lab\LabSession;
use App\Lab\PaidRunContext;
use App\Models\LabRunIntent;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextResponse as TranscriptResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

final class ConversationBoundaryTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
        $this->authorityFixtureOwner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        config(['prism.telemetry.enabled' => false, 'prism.providers.openai.api_key' => 'owned-audio-fixture']);
        $this->actingAs($this->authorityFixtureOwner, 'web');
    }

    public function test_direct_chat_with_an_owner_login_still_requires_matching_go(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Must not run')]);
        $this->refuses(fn () => app(ChatController::class)->run(Request::create('/lab/chat', 'POST', $this->chatInput())));
        $fake->assertCallCount(0);
    }

    public function test_direct_voice_with_an_owner_login_still_requires_matching_go(): void
    {
        $fake = Prism::fake([new TranscriptResponse(text: 'Must not run'), TextResponseFake::make()->withText('Must not run'),
            new AudioResponse(audio: new GeneratedAudio(base64_encode('owned'), 'audio/mpeg'))]);
        $this->refuses(fn () => app()->call([app(AgentConversationController::class), 'voice'], [
            'request' => Request::create('/lab/agent/voice', 'POST', $this->voiceInput()),
        ]));
        $fake->assertCallCount(0);
    }

    public function test_exact_chat_runs_and_growing_history_does_not_invalidate_its_scope(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned answer')->withMessages(collect([
            new UserMessage('Owned question'), new AssistantMessage('Owned answer'),
        ]))]);
        $this->withApprovedLabRun('chat.run', $this->chatInput(), function (): void {
            $result = app(ChatController::class)->run(Request::create('/lab/chat', 'POST', $this->chatInput()));
            $this->assertSame(200, $result->getStatusCode());
            $this->assertSame('Owned answer', $result->getData(true)['text']);
            $this->assertSame('chat.run', app(PaidRunContext::class)->authorizedRun(['chat.run'])->action);
            $this->assertSame(2, app(LabSession::class)->resolveScope('lab:agent')->thread()->storedMessages()->count());
        });
        $fake->assertCallCount(1);
        $this->assertSame(1, User::query()->count());
    }

    #[DataProvider('changedChatInputs')]
    public function test_direct_chat_binds_every_actual_argument(string $key, string $value): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Must not run')]);
        $this->withApprovedLabRun('chat.run', $this->chatInput(), function () use ($key, $value): void {
            $input = array_replace($this->chatInput(), [$key => $value]);
            $this->refuses(fn () => app(LabConversation::class)->chat($input['provider'], $input['model'], $input['feature'], $input['prompt']));
        });
        $fake->assertCallCount(0);
    }

    public static function changedChatInputs(): array
    {
        return [['provider', 'openai'], ['model', 'other-model'], ['feature', 'research'], ['prompt', 'Other question']];
    }

    public function test_stale_session_model_mode_and_step_overrides_cannot_expand_this_chat(): void
    {
        app(LabSession::class)->resolveScope('lab:agent')->usingProvider('openai')->usingModel('stale-model')->usingMode('research')->usingMaxSteps(999);
        config(['prism-harness.agent.max_steps_ceiling' => 3]);
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned answer')]);
        $this->withApprovedLabRun('chat.run', $this->chatInput(), fn () => app(LabConversation::class)->chat(...array_values($this->chatInput())));
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('owned-model', $requests[0]->model());
            $this->assertSame(3, $requests[0]->maxSteps());
        });
    }

    public function test_exact_voice_preserves_native_transcript_model_and_audio(): void
    {
        $fake = Prism::fake([new TranscriptResponse(text: 'Owned spoken question'),
            TextResponseFake::make()->withText('Owned spoken answer')->withMessages(collect([
                new UserMessage('Owned spoken question'), new AssistantMessage('Owned spoken answer'),
            ])), new AudioResponse(audio: new GeneratedAudio(base64_encode('owned-speech'), 'audio/mpeg'))]);
        $reply = $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => app()->call([app(AgentConversationController::class), 'voice'], [
            'request' => Request::create('/lab/agent/voice', 'POST', $this->voiceInput()),
        ]));
        $this->assertSame(200, $reply->getStatusCode());
        $this->assertSame('Owned spoken question', $reply->getData(true)['heard']);
        $this->assertSame('Owned spoken answer', $reply->getData(true)['text']);
        $this->assertSame(base64_encode('owned-speech'), $reply->getData(true)['audio']);
        $fake->assertCallCount(3);
    }

    public function test_silence_does_not_spend_a_model_or_speech_call(): void
    {
        $fake = Prism::fake([new TranscriptResponse(text: '   ')]);
        $reply = $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => app(LabConversation::class)->voice(...array_values($this->voiceInput())));
        $this->assertTrue($reply->empty);
        $this->assertNull($reply->audio);
        $fake->assertCallCount(1);
    }

    public function test_tool_only_voice_turn_keeps_null_audio(): void
    {
        $fake = Prism::fake([new TranscriptResponse(text: 'Owned question'), TextResponseFake::make()->withText('')]);
        $reply = $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => app(LabConversation::class)->voice(...array_values($this->voiceInput())));
        $this->assertFalse($reply->empty);
        $this->assertSame('', $reply->text);
        $this->assertNull($reply->audio);
        $fake->assertCallCount(2);
    }

    #[DataProvider('changedVoiceInputs')]
    public function test_voice_binds_both_audio_and_mime_before_transcription(string $key, string $value): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('agent.voice', $this->voiceInput(), function () use ($key, $value): void {
            $input = array_replace($this->voiceInput(), [$key => $value]);
            $this->refuses(fn () => app(LabConversation::class)->voice($input['audio'], $input['mime']));
        });
        $fake->assertCallCount(0);
    }

    public static function changedVoiceInputs(): array
    {
        return [['audio', base64_encode('Other synthetic audio')], ['mime', 'audio/webm']];
    }

    public function test_a_conversation_clear_after_go_refuses_before_any_inference(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Must not run')]);
        $this->withApprovedLabRun('chat.run', $this->chatInput(), function (): void {
            app(LabSession::class)->resolveScope('lab:agent')->newConversation();
            $this->refuses(fn () => app(LabConversation::class)->chat(...array_values($this->chatInput())));
        });
        $fake->assertCallCount(0);
    }

    public function test_attachment_replacement_after_go_refuses_before_any_inference(): void
    {
        app(LabSession::class)->resolveScope('lab:agent')->usingCapability('browser', ['id' => 'owned-original-attachment']);
        $fake = Prism::fake([TextResponseFake::make()->withText('Must not run')]);
        $this->withApprovedLabRun('chat.run', $this->chatInput(), function (): void {
            app(LabSession::class)->resolveScope('lab:agent')->usingCapability('browser', ['id' => 'other-owned-attachment']);
            $this->refuses(fn () => app(LabConversation::class)->chat(...array_values($this->chatInput())));
        });
        $fake->assertCallCount(0);
    }

    public function test_chat_failures_keep_private_provider_content_out_of_the_response_and_log(): void
    {
        $logger = Log::spy();
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['message' => 'owned-private-chat-error'],
        ], 400)]);
        $reply = $this->withApprovedLabRun('chat.run', $this->chatInput(), fn () => app(ChatController::class)->run(
            Request::create('/lab/chat', 'POST', $this->chatInput())));
        $this->assertSame(502, $reply->getStatusCode());
        $this->assertStringNotContainsString('owned-private-chat-error', $reply->getContent());
        $this->assertSame('failed', $reply->getData(true)['harness']['state']['status']);
        $logger->shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Lab chat turn failed.'
            && array_keys($context) === ['failure'] && is_string($context['failure']) && class_exists($context['failure']));
    }

    public function test_a_broken_logger_does_not_mask_the_chat_failure_response(): void
    {
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('owned logger failure'));
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['message' => 'owned-private-chat-error'],
        ], 400)]);
        $reply = $this->withApprovedLabRun('chat.run', $this->chatInput(), fn () => app(ChatController::class)->run(
            Request::create('/lab/chat', 'POST', $this->chatInput())));
        $this->assertSame(502, $reply->getStatusCode());
    }

    public function test_native_transcription_revocation_blocks_the_model_and_speech(): void
    {
        $this->nativeVoiceFixture(function (): void {
            LabRunIntent::query()->where('action', 'agent.voice')->update(['revoked_at' => now()]);
        });
        $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => $this->refuses(fn () => app(LabConversation::class)->voice(...array_values($this->voiceInput()))));
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'api.openai.com'))->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }

    public function test_native_model_revocation_blocks_speech_without_erasing_the_turn(): void
    {
        $this->nativeVoiceFixture(null, function (): void {
            LabRunIntent::query()->where('action', 'agent.voice')->update(['revoked_at' => now()]);
        });
        $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => $this->refuses(fn () => app(LabConversation::class)->voice(...array_values($this->voiceInput()))));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/audio/speech'));
        $this->assertNotSame([], iterator_to_array(app(LabSession::class)->resolveScope('lab:agent')->thread()->messages()));
    }

    public function test_voice_failures_log_only_the_kind_and_return_the_fixed_response(): void
    {
        $logger = Log::spy();
        Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response(['error' => ['message' => 'owned-private-provider-error']], 400)]);
        $reply = $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => app()->call([app(AgentConversationController::class), 'voice'], [
            'request' => Request::create('/lab/agent/voice', 'POST', $this->voiceInput()),
        ]));
        $this->assertSame(503, $reply->getStatusCode());
        $this->assertStringNotContainsString('owned-private-provider-error', $reply->getContent());
        $logger->shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Lab voice turn failed.'
            && array_keys($context) === ['failure'] && is_string($context['failure']) && class_exists($context['failure']));
    }

    public function test_a_broken_logger_does_not_mask_the_voice_failure_response(): void
    {
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('owned logger failure'));
        Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response(['error' => ['message' => 'owned-private-provider-error']], 400)]);
        $reply = $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => app()->call([app(AgentConversationController::class), 'voice'], [
            'request' => Request::create('/lab/agent/voice', 'POST', $this->voiceInput()),
        ]));
        $this->assertSame(503, $reply->getStatusCode());
    }

    public function test_voice_selection_resolves_freshly_instead_of_reusing_old_constructor_models(): void
    {
        app(VoiceExchange::class);
        config(['team.voice.transcribe_model' => 'owned-new-stt', 'team.voice.speak_model' => 'owned-new-tts']);
        $fake = Prism::fake([new TranscriptResponse(text: 'Owned question'), TextResponseFake::make()->withText('Owned answer'),
            new AudioResponse(audio: new GeneratedAudio(base64_encode('owned'), 'audio/mpeg'))]);
        $this->withApprovedLabRun('agent.voice', $this->voiceInput(), fn () => app(LabConversation::class)->voice(...array_values($this->voiceInput())));
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('owned-new-stt', $requests[0]->model());
            $this->assertSame('owned-new-tts', $requests[2]->model());
        });
    }

    #[DataProvider('effectiveConfigurationChanges')]
    public function test_capability_policy_and_workspace_changes_after_go_refuse(string $key, mixed $value): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('chat.run', $this->chatInput(), function () use ($key, $value): void {
            config([$key => $value]);
            $this->refuses(fn () => app(LabConversation::class)->chat(...array_values($this->chatInput())));
        });
        $fake->assertCallCount(0);
    }

    public static function effectiveConfigurationChanges(): array
    {
        return [['capabilities.browser.enabled', false], ['capabilities.human_plus.approval_tools', ['unreviewed-tool']],
            ['prism-workspace.root', 'other-owned-root']];
    }

    public function test_capability_tokens_and_live_thread_output_are_not_copied_into_the_scope(): void
    {
        config(['capabilities.human_plus.fixture.token' => 'owned-secret-fixture-token']);
        app(LabSession::class)->resolveScope('lab:agent')->usingCapability('human_plus', ['id' => 'owned-attachment', 'token' => 'owned-private-attachment-token']);
        [, $scope] = $this->approvedLabRun('chat.run', $this->chatInput());
        $json = json_encode($scope->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('owned-secret-fixture-token', $json);
        $this->assertStringNotContainsString('owned-private-attachment-token', $json);
        $this->assertTrue($scope->payload['session']['human_plus_attached']);
    }

    private function nativeVoiceFixture(?Closure $afterTranscription = null, ?Closure $afterModel = null): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => function () use ($afterTranscription) {
                $afterTranscription?->__invoke();

                return Http::response(['text' => 'Owned native spoken question']);
            },
            'https://api.anthropic.com/v1/messages' => function () use ($afterModel) {
                $afterModel?->__invoke();

                return Http::response(['id' => 'owned-native-message', 'type' => 'message', 'role' => 'assistant',
                    'model' => config('team.coordinator.model'), 'content' => [['type' => 'text', 'text' => 'Owned native answer']],
                    'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 12, 'output_tokens' => 8]]);
            },
            'https://api.openai.com/v1/audio/speech' => Http::response('owned-speech', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    private function chatInput(): array
    {
        return ['provider' => 'anthropic', 'model' => 'owned-model', 'feature' => 'text', 'prompt' => 'Owned question'];
    }

    private function voiceInput(): array
    {
        return ['audio' => base64_encode('Owned synthetic audio'), 'mime' => 'audio/wav'];
    }

    private function refuses(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Owner login was treated as permission for inference.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }
}
