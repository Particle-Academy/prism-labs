<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Harness\AgentResponse;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Harness\Voice\VoiceReply;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Audio;

/** Actual application inference boundaries; native Session remains an SDK API. */
final readonly class LabConversation
{
    public function __construct(private LabSession $sessions, private PaidRunContext $context) {}

    public function chat(string $provider, string $model, string $feature, string $prompt): AgentResponse
    {
        $input = compact('provider', 'model', 'feature', 'prompt');
        $this->context->requireInput(['chat.run'], $input);
        if (trim($prompt) === '' || mb_strlen($prompt) > 12000 || mb_strlen($model) > 120) {
            throw new PaidRunNotAuthorised;
        }
        $session = $this->session('chat.run', $input);
        $this->context->requireInput(['chat.run'], $input);

        return $session->send($prompt);
    }

    public function voice(string $audio, string $mime): VoiceReply
    {
        $input = compact('audio', 'mime');
        $this->context->requireInput(['agent.voice'], $input);
        if ($audio === '' || strlen($audio) > 2097152 || base64_decode($audio, true) === false
            || ! in_array($mime, ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/m4a'], true)) {
            throw new PaidRunNotAuthorised;
        }
        $session = $this->session('agent.voice', $input);
        // Resolve freshly from the same frozen config, not a prior singleton's
        // model/provider/voice selection. The native SDK owns each audio call.
        $voice = app(VoiceExchange::class);
        $heard = $voice->transcribe(Audio::fromBase64($audio, $mime));
        if ($heard === '') {
            return new VoiceReply(heard: '', text: '', audio: null, empty: true);
        }
        $this->context->requireInput(['agent.voice'], $input);
        $text = trim($session->send($heard)->text());
        $generated = null;
        if ($text !== '') {
            $this->context->requireInput(['agent.voice'], $input);
            $generated = $voice->speak($text);
        }

        return new VoiceReply(heard: $heard, text: $text, audio: $generated, empty: false);
    }

    private function session(string $action, array $input): Session
    {
        $settings = self::settings($action, $input);

        return $this->sessions->resolveScope('lab:agent')->usingProvider($settings['provider'])
            ->usingModel($settings['model'])->usingMode($settings['mode'])->usingMaxSteps($settings['max_steps']);
    }

    /** Effective values applied by the application, independent of stale session overrides. */
    public static function settings(string $action, array $input): array
    {
        if (! in_array($action, ['chat.run', 'agent.send', 'agent.voice'], true)) {
            throw new PaidRunNotAuthorised;
        }
        if ($action === 'chat.run' && ! in_array($input['feature'] ?? null, ['text', 'tools', 'research'], true)) {
            throw new PaidRunNotAuthorised;
        }
        $mode = $action === 'chat.run' && ($input['feature'] ?? null) === 'research' ? 'research' : 'chat';
        $provider = $action === 'chat.run' ? ($input['provider'] ?? null) : config('team.coordinator.provider');
        $model = $action === 'chat.run' ? ($input['model'] ?? null) : config('team.coordinator.model');
        $steps = config('prism-harness.agent.modes.'.$mode.'.max_steps');
        $ceiling = config('prism-harness.agent.max_steps_ceiling');
        if (! is_string($provider) || $provider === '' || ! is_string($model) || $model === ''
            || ! is_int($steps) || $steps < 1 || ($ceiling !== null && (! is_int($ceiling) || $ceiling < 1))) {
            throw new PaidRunNotAuthorised;
        }

        $settings = ['provider' => $provider, 'model' => $model, 'mode' => $mode,
            'max_steps' => $ceiling === null ? $steps : min($steps, $ceiling),
            'tools' => config('prism-harness.agent.modes.'.$mode.'.tools', [])];
        if ($action === 'agent.voice') {
            $settings['voice'] = self::voiceSettings();
        }

        return $settings;
    }

    /** The same selection is displayed for approval and used by the SDK factory. */
    public static function voiceSettings(): array
    {
        $config = (array) config('team.voice', []);
        $settings = ['provider' => $config['provider'] ?? 'openai',
            'transcribe_model' => $config['transcribe_model'] ?? 'whisper-1',
            'speak_model' => $config['speak_model'] ?? 'tts-1', 'voice' => $config['voice'] ?? 'alloy'];
        foreach ($settings as $value) {
            if (! is_string($value) || $value === '') {
                throw new PaidRunNotAuthorised;
            }
        }
        if (Provider::tryFrom($settings['provider']) === null) {
            throw new PaidRunNotAuthorised;
        }

        return $settings;
    }
}
