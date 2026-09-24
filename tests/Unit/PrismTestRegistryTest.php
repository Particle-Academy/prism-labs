<?php

namespace Tests\Unit;

use App\Lab\PrismTestRegistry;
use PHPUnit\Framework\TestCase;

class PrismTestRegistryTest extends TestCase
{
    public function test_it_exposes_the_initial_provider_feature_matrix(): void
    {
        $cases = (new PrismTestRegistry)->all();

        $this->assertCount(12, $cases);
        $this->assertSame(['text', 'streaming'], $cases->where('provider', 'perplexity')->pluck('feature')->all());
        $this->assertSame(
            ['embeddings', 'images', 'streaming', 'structured', 'text', 'tools'],
            $cases->pluck('feature')->unique()->sort()->values()->all(),
        );
        $this->assertTrue($cases->firstWhere('id', 'openai.images')->costly);
        $this->assertNull((new PrismTestRegistry)->find('unknown'));
    }
}
