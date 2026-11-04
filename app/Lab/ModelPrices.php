<?php

declare(strict_types=1);

namespace App\Lab;

use Carbon\CarbonImmutable;

/** Dated list prices joined onto discovered IDs; never a source of model availability. */
final class ModelPrices
{
    public const CHECKED_ON = '2026-11-04';

    private const ANTHROPIC_SOURCE = 'https://platform.claude.com/docs/en/about-claude/pricing';

    private const ANTHROPIC_MODELS_SOURCE = 'https://platform.claude.com/docs/en/about-claude/models/overview';

    /** @var list<string> Explicitly listed under Legacy models in the official overview. */
    private const LEGACY_ANTHROPIC = ['claude-fable-5', 'claude-opus-5', 'claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-opus-4-5', 'claude-sonnet-5', 'claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-haiku-4-5-20251001'];

    /** @return array{label: string, checked_on: string, source: string}|null */
    public function lifecycleForModel(string $provider, string $id): ?array
    {
        $datedAnthropic = match ($id) {
            'claude-opus-4-5-20251101' => ['Legacy', 'https://platform.claude.com/docs/en/models/opus-4-5/overview'],
            'claude-sonnet-4-5', 'claude-sonnet-4-5-20250929' => ['Deprecated', 'https://platform.claude.com/docs/en/models/sonnet-4-5/overview'],
            default => null,
        };
        if ($provider === 'anthropic' && $datedAnthropic !== null) {
            return ['label' => $datedAnthropic[0], 'checked_on' => self::CHECKED_ON, 'source' => $datedAnthropic[1]];
        }
        if ($provider === 'anthropic' && in_array($id, self::LEGACY_ANTHROPIC, true)) {
            return ['label' => 'Legacy', 'checked_on' => self::CHECKED_ON, 'source' => self::ANTHROPIC_MODELS_SOURCE];
        }
        if ($provider === 'openai') {
            $quote = $this->openAiModels()[$id] ?? null;
            if (($quote['lifecycle'] ?? null) !== null) {
                return ['label' => $quote['lifecycle'], 'checked_on' => self::CHECKED_ON,
                    'source' => $quote['identity_source'] ?? $quote['source']];
            }
        }

        return null;
    }

    /** @var array<string, array{float, float, float, float, float}> Input, output, cache read, 5m write, 1h write. */
    private const ANTHROPIC = [
        'claude-fable-5-1' => [10.0, 50.0, 0.25, 12.5, 20.0],
        'claude-fable-5' => [10.0, 50.0, 1.0, 12.5, 20.0],
        'claude-opus-5-5' => [4.0, 20.0, 0.2, 5.0, 8.0],
        'claude-opus-5' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-8' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-7' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-6' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-5' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-5-20251101' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.1, 2.5, 4.0],
        'claude-sonnet-5' => [2.0, 10.0, 0.2, 2.5, 4.0],
        'claude-sonnet-4-6' => [3.0, 15.0, 0.3, 3.75, 6.0],
        'claude-sonnet-4-5' => [3.0, 15.0, 0.3, 3.75, 6.0],
        'claude-sonnet-4-5-20250929' => [3.0, 15.0, 0.3, 3.75, 6.0],
        'claude-haiku-4-5' => [1.0, 5.0, 0.1, 1.25, 2.0],
        'claude-haiku-4-5-20251001' => [1.0, 5.0, 0.1, 1.25, 2.0],
    ];

    public function stale(): bool
    {
        return CarbonImmutable::parse(self::CHECKED_ON)->lt(now()->startOfDay()->subDays(30));
    }

    /** @return array<string, mixed>|null */
    public function forModel(string $provider, string $id): ?array
    {
        $note = null;
        $expired = false;
        if ($provider === 'anthropic' && $id === 'claude-haiku-5-5') {
            $rates = [
                $this->rate('Up to 100,000 input tokens', [0.1, 0.5, 0.01, 0.125, 0.2]),
                $this->rate('Over 100,000 input tokens', [0.5, 2.5, 0.05, 0.625, 1.0]),
            ];
            $source = self::ANTHROPIC_SOURCE;
        } elseif ($provider === 'anthropic' && isset(self::ANTHROPIC[$id])) {
            $rates = [$this->rate('Standard tokens', self::ANTHROPIC[$id])];
            $source = match ($id) {
                'claude-opus-4-5', 'claude-opus-4-5-20251101' => 'https://platform.claude.com/docs/en/models/opus-4-5/overview',
                'claude-sonnet-4-5', 'claude-sonnet-4-5-20250929' => 'https://platform.claude.com/docs/en/models/sonnet-4-5/overview',
                default => self::ANTHROPIC_SOURCE,
            };
        } elseif ($provider === 'openai') {
            $quote = $this->openAiModels()[$id] ?? null;
            if ($quote === null) {
                return null;
            }
            $rates = array_map(function (array $rate): array {
                $mapped = $this->rate($rate['context'], [(float) $rate['input'], (float) $rate['output'],
                    isset($rate['cache_read']) ? (float) $rate['cache_read'] : null, null, null]);
                if ($rate['cache_write_30m'] !== null || $rate['cache_write_12h'] !== null) {
                    $mapped['cache_write_30m'] = $rate['cache_write_30m'] === null ? null : (float) $rate['cache_write_30m'];
                    $mapped['cache_write_12h'] = $rate['cache_write_12h'] === null ? null : (float) $rate['cache_write_12h'];
                }

                return $mapped;
            }, $quote['rates']);
            $source = count($rates) > 1 || isset($rates[0]['cache_write_30m']) || isset($rates[0]['cache_write_12h'])
                ? $quote['source'] : ($quote['identity_source'] ?? $quote['source']);
            $note = $quote['note'] ?? null;
            $expired = isset($quote['expires_on']) && now()->toDateString() > $quote['expires_on'];
        } else {
            return null;
        }

        return ['input' => $rates[0]['input'], 'rates' => $rates, 'currency' => 'USD', 'unit' => 'million_tokens',
            'checked_on' => self::CHECKED_ON, 'source' => $source, 'stale' => $this->stale() || $expired,
            ...($note === null ? [] : ['note' => $note])];
    }

    /** @return array<string, array<string, mixed>> */
    private function openAiModels(): array
    {
        static $models;

        return $models ??= json_decode(file_get_contents(resource_path('data/openai-model-prices.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{float, float, float, ?float, ?float}  $values
     * @return array<string, float|string|null>
     */
    private function rate(string $context, array $values): array
    {
        return ['context' => $context, 'input' => $values[0], 'output' => $values[1], 'cache_read' => $values[2],
            'cache_write_5m' => $values[3], 'cache_write_1h' => $values[4]];
    }
}
