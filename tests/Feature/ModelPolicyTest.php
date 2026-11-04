<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkPreflight;
use App\Lab\ModelCatalogue;
use App\Lab\ModelPolicy;
use App\Models\BenchmarkSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CachesModelRoster;
use Tests\TestCase;

/**
 * Which models the Lab may spend on.
 *
 * A spec is frozen when it is approved, so a model chosen at design time is
 * one that every run of that spec pays for until somebody cuts a new revision.
 * The policy is applied BEFORE a lane starts, which is the only point at which
 * refusing is free.
 */
class ModelPolicyTest extends TestCase
{
    use CachesModelRoster;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheModelRoster(includeOpenAI: true);
    }

    public function test_the_default_is_the_cheap_tier_on_the_providers_we_hold_keys_for(): void
    {
        // A default that included the expensive tier would mean the first
        // benchmark anyone ran cost the most it could.
        $allowed = app(ModelPolicy::class)->allowed();

        $this->assertSame(['anthropic:claude-sonnet-5-5', 'openai:gpt-5.4-mini'], $allowed);
        $this->assertNotContains('anthropic:claude-opus-5', $allowed);
    }

    public function test_an_unticked_model_is_refused_before_the_lane_starts(): void
    {
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
        $spec = new BenchmarkSpec;
        $spec->lane_matrix = [['language' => 'php', 'provider' => 'anthropic', 'model' => 'claude-opus-5']];

        $failures = app(BenchmarkPreflight::class)->failures($spec);

        $this->assertNotSame([], $failures);
        $this->assertStringContainsString('not enabled for testing', $failures[0]);
        $this->assertStringContainsString('/lab/models', $failures[0]);
    }

    public function test_a_model_nobody_has_heard_of_gets_a_different_sentence(): void
    {
        // The two refusals need different words. A model nobody has heard of is
        // probably a typo or a retired id; one the operator has not ticked is a
        // decision they can change in a click. "Not allowed" for both sends
        // someone hunting for a bug in the first case and a model in the second.
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
        $spec = new BenchmarkSpec;
        $spec->lane_matrix = [['language' => 'php', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514']];

        $failures = app(BenchmarkPreflight::class)->failures($spec);

        $this->assertStringContainsString('not a model this Lab knows', $failures[0]);
        $this->assertStringNotContainsString('not enabled for testing', $failures[0]);
    }

    public function test_a_ticked_model_passes(): void
    {
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
        app(ModelPolicy::class)->allow(['anthropic:claude-opus-5']);

        $spec = new BenchmarkSpec;
        $spec->lane_matrix = [['language' => 'php', 'provider' => 'anthropic', 'model' => 'claude-opus-5']];

        $this->assertSame([], app(BenchmarkPreflight::class)->failures($spec));
    }

    public function test_a_model_dropped_from_the_catalogue_stops_being_allowed_without_anyone_clearing_it(): void
    {
        // The stored row is intersected with the catalogue on every read, so a
        // retired id cannot keep spending because a settings row outlived it.
        app(ModelPolicy::class)->allow(['anthropic:claude-sonnet-5', 'anthropic:a-model-that-was-removed']);

        $this->assertSame(['anthropic:claude-sonnet-5'], app(ModelPolicy::class)->allowed());
    }

    public function test_enabling_nothing_is_a_real_choice_and_refuses_everything(): void
    {
        config(['prism.providers.anthropic.api_key' => 'sk-test']);
        app(ModelPolicy::class)->allow([]);

        $spec = new BenchmarkSpec;
        $spec->lane_matrix = [['language' => 'php', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5']];

        $this->assertSame([], app(ModelPolicy::class)->allowed());
        $this->assertNotSame([], app(BenchmarkPreflight::class)->failures($spec));
    }

    public function test_a_provider_without_a_key_is_visible_without_inventing_model_rows(): void
    {
        config(['prism.providers.openai.api_key' => '']);
        $provider = collect(app(ModelCatalogue::class)->providers())->firstWhere('key', 'openai');
        $this->assertNotNull($provider);
        $this->assertFalse($provider['configured']);
        $this->assertSame([], $provider['models']);
        $this->assertStringContainsString('no API key', $provider['message']);
    }
}
