<?php

declare(strict_types=1);

namespace App\Lab;

/**
 * The models a benchmark lane may be built from, and what each one costs to
 * run in rough terms.
 *
 * A CURATED list, not a provider query. No provider exposes "the models you
 * should be spending money on", and a benchmark that silently picks the
 * largest available model is a benchmark that bills like one. The operator
 * chooses from this list; see {@see ModelPolicy}.
 *
 * NEVER add an id that has not been verified against the provider. A retired
 * or invented id does not fail at configuration time — it fails on the first
 * request, mid-run, and reads as a result rather than a typo. That has already
 * happened twice in this workspace: `claude-sonnet-4-20250514` 404'd a whole
 * lane, and a spec asked for a provider called `google`, which Prism has never
 * had. Both were frozen into approved specs before anyone noticed.
 */
final class ModelCatalogue
{
    /**
     * @var array<string, list<array{id: string, label: string, tier: string}>>
     */
    private const MODELS = [
        'anthropic' => [
            ['id' => 'claude-haiku-4-5', 'label' => 'Haiku 4.5', 'tier' => 'cheap'],
            ['id' => 'claude-sonnet-5', 'label' => 'Sonnet 5', 'tier' => 'cheap'],
            ['id' => 'claude-fable-5-1', 'label' => 'Fable 5.1', 'tier' => 'mid'],
            ['id' => 'claude-opus-5', 'label' => 'Opus 5', 'tier' => 'expensive'],
        ],
        'openai' => [
            ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini', 'tier' => 'cheap'],
        ],
    ];

    /**
     * What is enabled when the operator has never chosen.
     *
     * The cheap tier on the two providers this Lab holds keys for. A default
     * that included the expensive tier would mean the first benchmark anyone
     * ran cost the most it could, which is not a default anyone would pick
     * deliberately.
     *
     * @var list<string>
     */
    public const DEFAULT_ENABLED = ['anthropic:claude-sonnet-5', 'openai:gpt-4.1-mini'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * Every catalogued model, annotated with whether its provider is actually
     * reachable. A model whose provider has no credential is SHOWN and marked,
     * never hidden — hiding it turns "you have not set a key" into "that model
     * does not exist".
     *
     * @return list<array{key: string, provider: string, provider_label: string, id: string, label: string, tier: string, configured: bool}>
     */
    public function all(): array
    {
        $rows = [];

        foreach (self::MODELS as $provider => $models) {
            $descriptor = $this->providers->find($provider);

            foreach ($models as $model) {
                $rows[] = [
                    'key' => $provider.':'.$model['id'],
                    'provider' => $provider,
                    'provider_label' => is_array($descriptor) ? (string) ($descriptor['label'] ?? $provider) : $provider,
                    'id' => $model['id'],
                    'label' => $model['label'],
                    'tier' => $model['tier'],
                    'configured' => $this->providers->isConfigured($provider),
                ];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    public function has(string $provider, string $model): bool
    {
        return in_array($provider.':'.$model, $this->keys(), true);
    }
}
