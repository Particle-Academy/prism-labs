<?php

declare(strict_types=1);

namespace App\Lab;

/**
 * Single source of truth for which Prism providers the Lab can reach.
 *
 * The provider LIST comes from Prism's own config, never from a copy here, so
 * a provider added to the package shows up without touching the Lab. This
 * class only adds what the config cannot express: a display label, which env
 * var holds the credential, and a sensible default model to prefill.
 *
 * Anything in Prism's config with no descriptor below is still listed — as
 * undescribed rather than hidden — so a new provider is visibly missing
 * instead of silently absent.
 */
class ProviderRegistry
{
    /**
     * @var array<string, array{label: string, env: ?string, model: ?string, modality: string}>
     */
    private const DESCRIPTORS = [
        'openai' => ['label' => 'OpenAI', 'env' => 'OPENAI_API_KEY', 'model' => 'gpt-4.1-mini', 'modality' => 'text'],
        'anthropic' => ['label' => 'Anthropic', 'env' => 'ANTHROPIC_API_KEY', 'model' => 'claude-opus-5', 'modality' => 'text'],
        // A moving alias, not a pinned version: gemini-2.0-flash sat here until
        // Google delisted it, and a prefilled default that names a withdrawn
        // model fails on the user's first request rather than at configuration
        // time. Mistral's default already works this way.
        'gemini' => ['label' => 'Gemini', 'env' => 'GEMINI_API_KEY', 'model' => 'gemini-flash-latest', 'modality' => 'text'],
        // deepseek-chat and deepseek-reasoner were both withdrawn on 2026-08-25
        // when DeepSeek rotated to a v4 generation. Same failure the comment
        // above describes for gemini-2.0-flash: a prefilled default naming a
        // withdrawn model fails on the user's first request, not at config time.
        'deepseek' => ['label' => 'DeepSeek', 'env' => 'DEEPSEEK_API_KEY', 'model' => 'deepseek-v4-flash', 'modality' => 'text'],
        'groq' => ['label' => 'Groq', 'env' => 'GROQ_API_KEY', 'model' => null, 'modality' => 'text'],
        'mistral' => ['label' => 'Mistral', 'env' => 'MISTRAL_API_KEY', 'model' => 'mistral-large-latest', 'modality' => 'text'],
        'xai' => ['label' => 'xAI', 'env' => 'XAI_API_KEY', 'model' => null, 'modality' => 'text'],
        'openrouter' => ['label' => 'OpenRouter', 'env' => 'OPENROUTER_API_KEY', 'model' => null, 'modality' => 'text'],
        'requesty' => ['label' => 'Requesty', 'env' => 'REQUESTY_API_KEY', 'model' => null, 'modality' => 'text'],
        'perplexity' => ['label' => 'Perplexity', 'env' => 'PERPLEXITY_API_KEY', 'model' => 'sonar', 'modality' => 'text'],
        'qwen' => ['label' => 'Qwen', 'env' => 'QWEN_API_KEY', 'model' => null, 'modality' => 'text'],
        'azure' => ['label' => 'Azure AI', 'env' => 'AZURE_AI_API_KEY', 'model' => null, 'modality' => 'text'],
        'z' => ['label' => 'Z.ai', 'env' => 'Z_API_KEY', 'model' => null, 'modality' => 'text'],
        'replicate' => ['label' => 'Replicate', 'env' => 'REPLICATE_API_KEY', 'model' => null, 'modality' => 'text'],

        // No API key: reached over a URL, or authenticated out of band.
        'ollama' => ['label' => 'Ollama (local)', 'env' => null, 'model' => 'llama3.2', 'modality' => 'text'],
        'vertex' => ['label' => 'Vertex AI', 'env' => null, 'model' => null, 'modality' => 'text'],

        // Not text generation — listed so the Lab can say so rather than
        // offering them in a chat picker and failing at the API.
        'elevenlabs' => ['label' => 'ElevenLabs', 'env' => 'ELEVENLABS_API_KEY', 'model' => null, 'modality' => 'audio'],
        'voyageai' => ['label' => 'Voyage AI', 'env' => 'VOYAGEAI_API_KEY', 'model' => null, 'modality' => 'embeddings'],
    ];

    /**
     * Every provider Prism knows about, in config order.
     *
     * @return array<int, array{
     *     key: string, label: string, env: ?string, model: ?string,
     *     modality: string, requiresKey: bool, configured: bool, described: bool
     * }>
     */
    public function all(): array
    {
        $providers = [];

        /** @var array<string, mixed> $configured */
        $configured = (array) config('prism.providers', []);

        foreach ($configured as $key => $config) {
            $descriptor = self::DESCRIPTORS[$key] ?? null;
            $config = (array) $config;

            // A provider whose config carries an api_key slot needs one filled;
            // Ollama and Vertex are reached without one.
            $requiresKey = array_key_exists('api_key', $config);

            $providers[] = [
                'key' => (string) $key,
                'label' => $descriptor['label'] ?? ucfirst((string) $key),
                'env' => $descriptor['env'] ?? null,
                'model' => $descriptor['model'] ?? null,
                'modality' => $descriptor['modality'] ?? 'text',
                'requiresKey' => $requiresKey,
                'configured' => $requiresKey ? filled($config['api_key'] ?? null) : filled($config['url'] ?? null),
                'described' => $descriptor !== null,
            ];
        }

        return $providers;
    }

    /**
     * Providers usable for text generation right now.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableForText(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $p): bool => $p['configured'] && $p['modality'] === 'text',
        ));
    }

    /**
     * Provider keys accepted by the Lab's text endpoints — every text provider,
     * configured or not, so an unconfigured pick returns a helpful message
     * rather than a validation rejection that says nothing.
     *
     * @return array<int, string>
     */
    public function textProviderKeys(): array
    {
        return array_values(array_map(
            fn (array $p): string => $p['key'],
            array_filter($this->all(), fn (array $p): bool => $p['modality'] === 'text'),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $provider) {
            if ($provider['key'] === $key) {
                return $provider;
            }
        }

        return null;
    }

    public function isConfigured(string $key): bool
    {
        return (bool) ($this->find($key)['configured'] ?? false);
    }

    /**
     * How to switch a provider on, phrased for whoever is looking at the Lab.
     */
    public function setupHint(string $key): string
    {
        $provider = $this->find($key);

        if ($provider === null) {
            return "Unknown provider [{$key}].";
        }

        if ($provider['env'] !== null) {
            return "Set {$provider['env']} in repos/prism-sandbox/.env, then reload.";
        }

        if ($key === 'ollama') {
            return 'Start Ollama locally, or set OLLAMA_URL in repos/prism-sandbox/.env.';
        }

        return "Configure prism.providers.{$key} in repos/prism-sandbox/.env, then reload.";
    }

    /**
     * Providers Prism ships that this registry has no descriptor for. Empty is
     * the healthy state; anything here means the package gained a provider and
     * the Lab is showing it with a guessed label and no setup hint.
     *
     * @return array<int, string>
     */
    public function undescribed(): array
    {
        return array_values(array_map(
            fn (array $p): string => $p['key'],
            array_filter($this->all(), fn (array $p): bool => ! $p['described']),
        ));
    }
}
