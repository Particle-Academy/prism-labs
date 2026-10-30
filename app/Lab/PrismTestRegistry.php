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
            new PrismTestCase('perplexity.text', 'perplexity', 'sonar', 'text', 'Perplexity text'),
            new PrismTestCase('perplexity.streaming', 'perplexity', 'sonar', 'streaming', 'Perplexity streaming'),
            new PrismTestCase('perplexity.research.completed', 'perplexity', 'sonar-deep-research', 'deep_research', 'Deep research: complete with cited output', true),
            new PrismTestCase('perplexity.research.incomplete', 'perplexity', 'sonar-deep-research', 'deep_research', 'Deep research: low-token incomplete', true),
            new PrismTestCase('perplexity.research.failed', 'perplexity', 'sonar-deep-research', 'deep_research', 'Deep research: observe genuine failure (may remain unmeasured)', true),
            new PrismTestCase('perplexity.research.shape-deep', 'perplexity', 'sonar-deep-research', 'deep_research', 'Deep research: accepted request shape', true),
            new PrismTestCase('perplexity.research.shape-pro', 'perplexity', 'sonar-pro', 'deep_research', 'Sonar Pro: accepted request shape', true),
            new PrismTestCase('perplexity.research.long', 'perplexity', 'sonar-deep-research', 'deep_research', 'Deep research: configured long-run path', true),
            new PrismTestCase('perplexity.research.filters-deep', 'perplexity', 'sonar-deep-research', 'deep_research', 'Deep research: recency plus date acceptance', true),
            new PrismTestCase('perplexity.research.filters-pro', 'perplexity', 'sonar-pro', 'deep_research', 'Sonar Pro: recency plus date acceptance', true),
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
