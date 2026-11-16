<?php

declare(strict_types=1);

namespace App\Billing;

use App\Drift\ProviderWatchReader;
use App\Lab\ModelPrices;

/**
 * The Lab's configured rates against the drift watcher's models-dev prices
 * (G-291 part 2, Lab side). One tile per mismatched model.
 *
 * Two configured sources, labelled apart because they do different jobs:
 *  - `harness`: `prism-harness.pricing`, the rates Harness budgets and prices
 *    calls with. Compared at the EFFECTIVE rate: Harness charges an omitted
 *    cache rate at the input price, so an omitted cacheReadPerMTok against a
 *    published cache-read price is a mismatch (an overcharge), not a skip.
 *    A configured model the catalogue does not list cannot be verified, and
 *    gets a tile saying so.
 *  - `lab-list`: App\Lab\ModelPrices, the dated list prices the model
 *    catalogue and benchmark scorer show. Standard-tier rate only; fields it
 *    does not carry are skipped.
 *
 * A catalogue field that is null is "not published" and never compared.
 */
final class PriceMismatches
{
    private const TOLERANCE = 0.000001;

    /** Harness key => catalogue field. */
    private const HARNESS_FIELDS = [
        'inputPerMTok' => 'input', 'outputPerMTok' => 'output',
        'cacheReadPerMTok' => 'cache_read', 'cacheWritePerMTok' => 'cache_write',
    ];

    public function __construct(private readonly ProviderWatchReader $watch, private readonly ModelPrices $prices) {}

    /** @return array<string, mixed> */
    public function read(string $watchPath): array
    {
        $catalogue = $this->watch->catalogPrices($watchPath);
        $harness = (array) config('prism-harness.pricing', []);

        $tiles = [];
        $harnessModels = 0;
        foreach ($harness as $provider => $models) {
            if (! is_array($models)) {
                continue;
            }
            foreach ($models as $model => $rates) {
                if (! is_array($rates)) {
                    continue;
                }
                $harnessModels++;
                $published = $catalogue[$provider][$model] ?? null;
                if (! is_array($catalogue[$provider] ?? null)) {
                    $tiles[] = $this->tile((string) $provider, (string) $model, 'harness', [], 'catalogue_unreadable');

                    continue;
                }
                if ($published === null) {
                    $tiles[] = $this->tile((string) $provider, (string) $model, 'harness', [], 'not_in_catalogue');

                    continue;
                }
                $configured = [];
                foreach (self::HARNESS_FIELDS as $key => $field) {
                    $value = $rates[$key] ?? null;
                    if (! is_int($value) && ! is_float($value)) {
                        // Harness's own fallback for an omitted cache rate.
                        $value = in_array($key, ['cacheReadPerMTok', 'cacheWritePerMTok'], true) ? ($rates['inputPerMTok'] ?? null) : null;
                    }
                    $configured[$field] = is_int($value) || is_float($value) ? (float) $value : null;
                }
                $fields = $this->differences($configured, $published);
                if ($fields !== []) {
                    $tiles[] = $this->tile((string) $provider, (string) $model, 'harness', $fields, null);
                }
            }
        }

        foreach (['anthropic', 'openai'] as $provider) {
            foreach ($catalogue[$provider] ?? [] as $model => $published) {
                $quote = $this->prices->forModel($provider, (string) $model);
                $rate = $quote['rates'][0] ?? null;
                if (! is_array($rate)) {
                    continue;
                }
                $configured = [
                    'input' => $rate['input'] ?? null, 'output' => $rate['output'] ?? null,
                    'cache_read' => $rate['cache_read'] ?? null, 'cache_write' => $rate['cache_write_5m'] ?? null,
                ];
                $fields = $this->differences($configured, $published, skipUnconfigured: true);
                if ($fields !== []) {
                    $tiles[] = $this->tile($provider, (string) $model, 'lab-list', $fields, null);
                }
            }
        }

        $readable = array_filter($catalogue, fn (?array $models): bool => $models !== null);

        return [
            // unknown: there is no price snapshot to compare against at all.
            'status' => $readable === [] ? 'unknown' : ($tiles === [] ? 'ok' : 'mismatch'),
            'harnessConfiguredModels' => $harnessModels,
            'catalogueProviders' => array_keys($readable),
            'unreadableProviders' => array_keys(array_diff_key($catalogue, $readable)),
            'tiles' => $tiles,
        ];
    }

    /**
     * @param  array<string, float|null>  $configured
     * @param  array<string, float|null>  $published
     * @return list<array{field: string, configured: float|null, catalogue: float}>
     */
    private function differences(array $configured, array $published, bool $skipUnconfigured = false): array
    {
        $fields = [];
        foreach ($published as $field => $catalogue) {
            if ($catalogue === null || ! array_key_exists($field, $configured)) {
                continue;
            }
            $mine = $configured[$field];
            if ($mine === null && $skipUnconfigured) {
                continue;
            }
            if ($mine === null || abs((float) $mine - $catalogue) > self::TOLERANCE) {
                $fields[] = ['field' => $field, 'configured' => $mine === null ? null : (float) $mine, 'catalogue' => $catalogue];
            }
        }

        return $fields;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function tile(string $provider, string $model, string $source, array $fields, ?string $reason): array
    {
        return ['provider' => $provider, 'model' => $model, 'source' => $source, 'fields' => $fields, 'reason' => $reason];
    }
}
