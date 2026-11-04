<?php

declare(strict_types=1);

namespace App\Lab;

use Carbon\CarbonImmutable;

/** Dated list prices joined onto discovered IDs; never a source of model availability. */
final class ModelPrices
{
    public const CHECKED_ON = '2026-11-04';

    private const ANTHROPIC_SOURCE = 'https://platform.claude.com/docs/en/about-claude/pricing';

    private const OPENAI_SOURCE = 'https://developers.openai.com/api/docs/models/gpt-4.1-mini';

    /** @var array<string, array{float, float, float, float, float}> Input, output, cache read, 5m write, 1h write. */
    private const ANTHROPIC = [
        'claude-fable-5-1' => [10.0, 50.0, 0.25, 12.5, 20.0],
        'claude-fable-5' => [10.0, 50.0, 1.0, 12.5, 20.0],
        'claude-opus-5-5' => [4.0, 20.0, 0.2, 5.0, 8.0],
        'claude-opus-5' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-8' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-7' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-opus-4-6' => [5.0, 25.0, 0.5, 6.25, 10.0],
        'claude-sonnet-5-5' => [2.0, 10.0, 0.1, 2.5, 4.0],
        'claude-sonnet-5' => [2.0, 10.0, 0.2, 2.5, 4.0],
        'claude-sonnet-4-6' => [3.0, 15.0, 0.3, 3.75, 6.0],
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
        if ($provider === 'anthropic' && $id === 'claude-haiku-5-5') {
            $rates = [
                $this->rate('Up to 100,000 input tokens', [0.1, 0.5, 0.01, 0.125, 0.2]),
                $this->rate('Over 100,000 input tokens', [0.5, 2.5, 0.05, 0.625, 1.0]),
            ];
            $source = self::ANTHROPIC_SOURCE;
        } elseif ($provider === 'anthropic' && isset(self::ANTHROPIC[$id])) {
            $rates = [$this->rate('Standard tokens', self::ANTHROPIC[$id])];
            $source = self::ANTHROPIC_SOURCE;
        } elseif ($provider === 'openai' && in_array($id, ['gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14'], true)) {
            $rates = [$this->rate('Standard text tokens', [0.4, 1.6, 0.1, null, null])];
            $source = self::OPENAI_SOURCE;
        } else {
            return null;
        }

        return ['input' => $rates[0]['input'], 'rates' => $rates, 'currency' => 'USD', 'unit' => 'million_tokens',
            'checked_on' => self::CHECKED_ON, 'source' => $source, 'stale' => $this->stale()];
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
