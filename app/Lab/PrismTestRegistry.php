<?php

declare(strict_types=1);

namespace App\Lab;

use Illuminate\Support\Collection;

final class PrismTestRegistry
{
    /** @return Collection<int, PrismTestCase> */
    public function all(): Collection
    {
        return collect([
            new PrismTestCase('openai.text', 'openai', 'gpt-4.1-mini', 'text', 'OpenAI text'),
            new PrismTestCase('openai.streaming', 'openai', 'gpt-4.1-mini', 'streaming', 'OpenAI streaming'),
            new PrismTestCase('openai.tools', 'openai', 'gpt-4.1-mini', 'tools', 'OpenAI multi-step tools'),
            new PrismTestCase('openai.structured', 'openai', 'gpt-4.1-mini', 'structured', 'OpenAI structured output'),
            new PrismTestCase('openai.embeddings', 'openai', 'text-embedding-3-small', 'embeddings', 'OpenAI embeddings'),
            new PrismTestCase('openai.images', 'openai', 'dall-e-3', 'images', 'OpenAI image generation', true),
            new PrismTestCase('anthropic.text', 'anthropic', 'claude-opus-5', 'text', 'Anthropic text'),
            new PrismTestCase('anthropic.streaming', 'anthropic', 'claude-opus-5', 'streaming', 'Anthropic streaming'),
            new PrismTestCase('anthropic.tools', 'anthropic', 'claude-opus-5', 'tools', 'Anthropic multi-step tools'),
            new PrismTestCase('anthropic.structured', 'anthropic', 'claude-opus-5', 'structured', 'Anthropic structured output'),
        ]);
    }

    public function find(string $id): ?PrismTestCase
    {
        return $this->all()->firstWhere('id', $id);
    }
}
