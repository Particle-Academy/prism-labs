<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;

/** Consumer tests use an explicit successful listing; discovery itself has native HTTP controls. */
trait CachesModelRoster
{
    protected function cacheModelRoster(bool $includeOpenAI = false): void
    {
        $configured = config('prism.providers');
        config(['prism.providers' => array_intersect_key($configured, array_flip(['anthropic', 'openai'])),
            'prism.providers.anthropic.api_key' => 'fixture-only',
            'prism.providers.openai.api_key' => $includeOpenAI ? 'fixture-only' : '']);
        foreach (['anthropic' => ['claude-sonnet-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-haiku-4-5'], 'openai' => ['gpt-4.1-mini']] as $provider => $ids) {
            Cache::put('lab.model-listing.v1.'.$provider, ['status' => 'ready', 'checked_at' => now()->toIso8601String(), 'message' => null,
                'models' => array_map(fn (string $id): array => ['id' => $id, 'name' => $id, 'created_at' => null], $ids)], 600);
        }
    }
}
