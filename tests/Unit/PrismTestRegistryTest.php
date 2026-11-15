<?php

namespace Tests\Unit;

use App\Lab\PrismTestRegistry;
use PHPUnit\Framework\TestCase;

class PrismTestRegistryTest extends TestCase
{
    public function test_it_exposes_the_initial_provider_feature_matrix(): void
    {
        $cases = (new PrismTestRegistry)->all();

        $this->assertCount(20, $cases);
        $this->assertSame(['text', 'streaming'], $cases->where('provider', 'perplexity')->reject(fn ($case) => $case->feature === 'deep_research')->pluck('feature')->all());
        $this->assertCount(8, $cases->where('feature', 'deep_research'));
        $this->assertTrue($cases->where('feature', 'deep_research')->every(fn ($case) => $case->costly));
        $this->assertSame(
            ['deep_research', 'embeddings', 'images', 'streaming', 'structured', 'text', 'tools'],
            $cases->pluck('feature')->unique()->sort()->values()->all(),
        );
        $this->assertTrue($cases->firstWhere('id', 'openai.images')->costly);
        $this->assertNull((new PrismTestRegistry)->find('unknown'));
    }
}
