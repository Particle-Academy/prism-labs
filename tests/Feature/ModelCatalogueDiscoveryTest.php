<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkPreflight;
use App\Lab\ModelCatalogue;
use App\Lab\ModelPolicy;
use App\Lab\ModelPrices;
use App\Models\BenchmarkSpec;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModelCatalogueDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $anthropicRoster = [];

    private array $openaiRoster = [];

    private array $labels = [];

    private bool $rosterFakeInstalled = false;

    private bool $listingFailure = false;

    private array $unreachableProviders = [];

    private array $authenticationStatuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['prism.providers' => [
            'anthropic' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://anthropic.fixture/v1', 'version' => '2023-06-01'],
            'openai' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://openai.fixture/v1'],
        ]]);
        $this->roster();
    }

    private function roster(array $anthropic = ['claude-sonnet-5', 'claude-fable-5-1', 'claude-opus-5', 'claude-haiku-5-5', 'unpriced-future'], array $openai = ['gpt-4.1-mini', 'unpriced-openai']): void
    {
        $this->anthropicRoster = $anthropic;
        $this->openaiRoster = $openai;
        if ($this->rosterFakeInstalled) {
            return;
        }
        $this->rosterFakeInstalled = true;
        Http::fake([
            'https://anthropic.fixture/v1/models*' => fn () => isset($this->authenticationStatuses['anthropic']) ? Http::response(['error' => 'fixture-provider-secret private-auth-body'], $this->authenticationStatuses['anthropic']) : ($this->listingFailure || in_array('anthropic', $this->unreachableProviders, true) ? Http::response(['error' => 'fixture-provider-secret'], 503) : Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'display_name' => $this->labels[$id] ?? 'Returned '.$id], $this->anthropicRoster), 'has_more' => false])),
            'https://openai.fixture/v1/models*' => fn () => $this->listingFailure || in_array('openai', $this->unreachableProviders, true) ? Http::response(['error' => 'fixture-provider-secret'], 503) : Http::response(['data' => array_map(fn (string $id): array => ['id' => $id, 'created' => 1712361441], $this->openaiRoster)]),
        ]);
    }

    public function test_discovers_unpriced_models_instead_of_using_a_curated_roster(): void
    {
        $rows = app(ModelCatalogue::class)->all();
        $this->assertContains('anthropic:unpriced-future', array_column($rows, 'key'));
        $future = collect($rows)->firstWhere('key', 'anthropic:unpriced-future');
        $this->assertNull($future['price']);
        $this->assertSame('Returned unpriced-future', $future['label']);
        $this->assertNotContains($future['key'], app(ModelPolicy::class)->allowed());
        $this->assertSame(ModelCatalogue::DEFAULT_ENABLED, app(ModelPolicy::class)->allowed());
    }

    public function test_cheapest_selection_respects_explicitly_allowed_ids(): void
    {
        $this->assertSame(['provider' => 'anthropic', 'model' => 'claude-fable-5-1'], app(ModelCatalogue::class)->cheapestConfigured(['anthropic:claude-fable-5-1']));
        $this->assertNull(app(ModelCatalogue::class)->cheapestConfigured([]));
        $this->assertNull(app(ModelCatalogue::class)->cheapestConfigured(['anthropic:unpriced-future']));
    }

    public function test_authentication_rejection_has_private_actionable_copy_through_the_real_models_route(): void
    {
        $this->localRoutes();
        foreach ([401, 403] as $status) {
            Cache::flush();
            $this->authenticationStatuses = ['anthropic' => $status];
            $response = $this->get('/lab/models');
            $response->assertOk()->assertInertia(fn ($page) => $page
                ->where('providers.0.status', 'authentication_rejected')
                ->where('providers.0.message', 'The provider rejected this API key or its access. Update its credentials or permissions in the Lab configuration, then refresh.')
                ->where('providers.0.models', [])
                ->where('selection', ModelCatalogue::DEFAULT_ENABLED)
                ->where('allowed', ['openai:gpt-4.1-mini']));
            $this->assertStringNotContainsString('fixture-provider-secret', $response->getContent());
            $this->assertStringNotContainsString('private-auth-body', $response->getContent());
            $this->assertStringNotContainsString('anthropic.fixture', $response->getContent());
            $this->assertStringContainsString('rejected', app(ModelPolicy::class)->refusal('anthropic', 'claude-sonnet-5'));
        }
    }

    public function test_expanded_openai_prices_keep_context_and_cache_durations_honest(): void
    {
        $price = app(ModelPrices::class)->forModel('openai', 'gpt-6.1-sol');
        $this->assertIsArray($price);
        $this->assertSame('https://developers.openai.com/api/docs/pricing', $price['source']);
        $this->assertCount(2, $price['rates']);
        $this->assertSame([2.0, 10.0, 0.1, 2.5, 5.0], array_values(array_intersect_key($price['rates'][0], array_flip(['input', 'output', 'cache_read', 'cache_write_30m', 'cache_write_12h']))));
        $this->assertSame([4.0, 15.0, 0.2, 5.0, 10.0], array_values(array_intersect_key($price['rates'][1], array_flip(['input', 'output', 'cache_read', 'cache_write_30m', 'cache_write_12h']))));
        $this->assertNull($price['rates'][0]['cache_write_5m']);
        $this->assertNull($price['rates'][0]['cache_write_1h']);
        $this->assertSame(app(ModelPrices::class)->forModel('openai', 'gpt-5')['rates'], app(ModelPrices::class)->forModel('openai', 'gpt-5-2025-08-07')['rates']);
        $this->assertNull(app(ModelPrices::class)->forModel('openai', 'gpt-4o-2024-05-13'));
        $this->assertNull(app(ModelPrices::class)->forModel('openai', 'gpt-5-made-up-snapshot'));
        $this->assertNull(app(ModelPrices::class)->forModel('openai', 'gpt-image-2'));
        $this->assertNull(app(ModelPrices::class)->forModel('openai', 'text-embedding-3-small'));
    }

    public function test_dated_anthropic_prices_and_status_use_the_exact_models_own_pages(): void
    {
        foreach ([
            ['claude-opus-4-5-20251101', 5.0, 25.0, 'Legacy', 'https://platform.claude.com/docs/en/models/opus-4-5/overview'],
            ['claude-sonnet-4-5-20250929', 3.0, 15.0, 'Deprecated', 'https://platform.claude.com/docs/en/models/sonnet-4-5/overview'],
        ] as [$id, $input, $output, $status, $source]) {
            $price = app(ModelPrices::class)->forModel('anthropic', $id);
            $this->assertIsArray($price);
            $this->assertSame($input, $price['rates'][0]['input']);
            $this->assertSame($output, $price['rates'][0]['output']);
            $this->assertSame($source, $price['source']);
            $this->assertSame(['label' => $status, 'checked_on' => '2026-11-04', 'source' => $source], app(ModelPrices::class)->lifecycleForModel('anthropic', $id));
        }
        $this->assertSame(['anthropic:claude-sonnet-5', 'openai:gpt-4.1-mini'], ModelCatalogue::DEFAULT_ENABLED);
    }

    public function test_expanded_openai_promotional_prices_expire_before_the_monthly_review_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01T12:00:00Z'));
        try {
            $price = app(ModelPrices::class)->forModel('openai', 'gpt-5.6-sol');
            $this->assertIsArray($price);
            $this->assertTrue($price['stale']);
            $this->assertStringContainsString('2026-11-30', $price['note']);
            $this->assertFalse(app(ModelPrices::class)->stale());
        } finally {
            $this->travelBack();
        }
    }

    public function test_numeric_prices_choose_the_cheapest_eligible_model(): void
    {
        $this->assertSame(['provider' => 'openai', 'model' => 'gpt-4.1-mini'], app(ModelCatalogue::class)->cheapestConfigured(ModelCatalogue::DEFAULT_ENABLED));
        $this->assertSame(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'], app(ModelCatalogue::class)->cheapestConfigured(['openai:gpt-4.1-mini', 'anthropic:claude-haiku-5-5']));
    }

    public function test_provider_rows_sort_by_numeric_input_price_descending_with_unknown_last(): void
    {
        $rows = array_values(array_filter(app(ModelCatalogue::class)->all(), fn (array $row): bool => $row['provider'] === 'anthropic'));
        $this->assertSame(['claude-fable-5-1', 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-5-5', 'unpriced-future'], array_column($rows, 'id'));
        $this->assertSame(10.0, $rows[0]['price']['input']);
        $this->assertSame('2026-11-04', $rows[0]['price']['checked_on']);
        $this->assertSame('USD', $rows[0]['price']['currency']);
        $this->assertSame('million_tokens', $rows[0]['price']['unit']);
        $this->assertSame('https://platform.claude.com/docs/en/about-claude/pricing', $rows[0]['price']['source']);
        $this->assertNull($rows[4]['price']);
    }

    public function test_anthropic_cache_prices_and_both_haiku_context_tiers_are_explicit(): void
    {
        $price = app(ModelPrices::class)->forModel('anthropic', 'claude-haiku-5-5');
        $this->assertSame([
            ['context' => 'Up to 100,000 input tokens', 'input' => 0.1, 'output' => 0.5, 'cache_read' => 0.01, 'cache_write_5m' => 0.125, 'cache_write_1h' => 0.2],
            ['context' => 'Over 100,000 input tokens', 'input' => 0.5, 'output' => 2.5, 'cache_read' => 0.05, 'cache_write_5m' => 0.625, 'cache_write_1h' => 1.0],
        ], $price['rates']);
        $fable = app(ModelPrices::class)->forModel('anthropic', 'claude-fable-5-1');
        $this->assertSame([10.0, 50.0, 0.25, 12.5, 20.0], array_values(array_intersect_key($fable['rates'][0], array_flip(['input', 'output', 'cache_read', 'cache_write_5m', 'cache_write_1h']))));
    }

    public function test_openai_prices_have_the_official_source_and_cache_read_rate(): void
    {
        $price = app(ModelPrices::class)->forModel('openai', 'gpt-4.1-mini');
        $this->assertSame('https://developers.openai.com/api/docs/models/gpt-4.1-mini', $price['source']);
        $this->assertSame(['context' => 'Standard text tokens', 'input' => 0.4, 'output' => 1.6, 'cache_read' => 0.1, 'cache_write_5m' => null, 'cache_write_1h' => null], $price['rates'][0]);
        $this->assertNull(app(ModelPrices::class)->forModel('openai', 'gpt-4.1-mini-made-up-suffix'));
    }

    public function test_prices_become_stale_only_after_thirty_calendar_days(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 4));
        $this->assertFalse(app(ModelPrices::class)->stale());
        $this->travelTo(now()->setDate(2026, 12, 5));
        $this->assertTrue(app(ModelPrices::class)->stale());
        $this->assertTrue(app(ModelPrices::class)->forModel('openai', 'gpt-4.1-mini')['stale']);
        $this->travelBack();
    }

    public function test_cached_discovery_expires_and_explicit_refresh_replaces_it(): void
    {
        $catalogue = app(ModelCatalogue::class);
        $this->assertTrue($catalogue->has('anthropic', 'unpriced-future'));
        Http::assertSentCount(2);
        $this->roster(['replacement'], []);
        $this->assertTrue($catalogue->has('anthropic', 'unpriced-future'));
        Http::assertSentCount(2);
        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $this->assertTrue($catalogue->has('anthropic', 'replacement'));
        $this->assertFalse($catalogue->has('anthropic', 'unpriced-future'));
        Http::assertSentCount(4);
        $this->roster(['refreshed'], []);
        $catalogue->refresh();
        $this->assertTrue($catalogue->has('anthropic', 'refreshed'));
        Http::assertSentCount(6);
        $this->travelBack();
    }

    public function test_removed_enabled_models_are_not_retained_by_settings_or_defaults(): void
    {
        app(ModelPolicy::class)->allow(['anthropic:claude-sonnet-5']);
        $this->roster(['replacement'], []);
        app(ModelCatalogue::class)->refresh();
        $this->assertSame([], app(ModelPolicy::class)->allowed());
        DB::table('lab_settings')->delete();
        $this->assertSame([], app(ModelPolicy::class)->allowed());
        DB::table('lab_settings')->insert(['key' => 'benchmark.allowed_models', 'value' => 'broken']);
        $this->assertSame([], app(ModelPolicy::class)->allowed());
        $spec = new BenchmarkSpec;
        $spec->lane_matrix = [['language' => 'php', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5']];
        $this->assertNotSame([], app(BenchmarkPreflight::class)->failures($spec));
    }

    public function test_no_key_removes_cached_models_and_keeps_the_provider_visible(): void
    {
        app(ModelCatalogue::class)->all();
        config(['prism.providers.anthropic.api_key' => '']);
        $provider = collect(app(ModelCatalogue::class)->providers())->firstWhere('key', 'anthropic');
        $this->assertSame('unconfigured', $provider['status']);
        $this->assertSame([], $provider['models']);
        $this->assertStringContainsString('no API key', $provider['message']);
        $this->assertFalse(app(ModelCatalogue::class)->has('anthropic', 'claude-sonnet-5'));
        Http::assertSentCount(2);
    }

    public function test_failed_refresh_never_falls_back_to_the_previous_or_curated_roster(): void
    {
        app(ModelCatalogue::class)->all();
        $this->listingFailure = true;
        $providers = app(ModelCatalogue::class)->refresh();
        $this->assertCount(2, $providers);
        foreach ($providers as $provider) {
            $this->assertSame('unreachable', $provider['status']);
            $this->assertSame([], $provider['models']);
            $this->assertStringContainsString("couldn't reach", $provider['message']);
            $this->assertStringNotContainsString('fixture-provider-secret', json_encode($provider));
        }
        $this->assertSame([], app(ModelCatalogue::class)->all());
    }

    public function test_refused_providers_and_public_catalogue_scope_are_visible(): void
    {
        config(['prism.providers' => [
            'perplexity' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://refused.fixture'],
            'replicate' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://replicate.fixture/v1'],
        ]]);
        Http::fake(['https://replicate.fixture/v1/models' => Http::response(['results' => [['owner' => 'someone', 'name' => 'public']], 'next' => null])]);
        $providers = app(ModelCatalogue::class)->providers();
        $this->assertSame('unsupported', $providers[0]['status']);
        $this->assertSame([], $providers[0]['models']);
        $this->assertSame('Model listing for this provider is not implemented in the installed Prism package.', $providers[0]['message']);
        $this->assertStringContainsString('public catalogue', $providers[1]['scope']);
        $this->assertStringContainsString('not your account', $providers[1]['scope']);
        $this->assertSame('someone/public', $providers[1]['models'][0]['id']);
        Http::assertSentCount(1);
    }

    public function test_ids_never_gain_capabilities_from_their_spelling(): void
    {
        $this->roster([], ['gpt-text-vision-tools', 'audio-model', 'unknown-future']);
        $models = app(ModelCatalogue::class)->all();
        $this->assertCount(3, $models);
        foreach ($models as $model) {
            $this->assertSame('other', $model['kind']);
        }
    }

    public function test_page_preserves_returned_text_and_omits_all_configuration_secrets(): void
    {
        $this->localRoutes();
        $id = '<img-src=x-onerror=alert(1)>';
        $name = '<script>window.leaked=true</script>';
        $this->labels[$id] = $name;
        $this->roster([$id], []);
        $response = $this->get('/lab/models');
        $response->assertOk()->assertInertia(fn ($page) => $page->component('Lab/Models')->where('providers.0.models.0.id', $id)->where('providers.0.models.0.label', $name));
        $this->assertStringNotContainsString('fixture-provider-secret', $response->getContent());
        $this->assertStringNotContainsString('anthropic.fixture', $response->getContent());
        $this->assertStringNotContainsString('<script>window.leaked=true</script>', $response->getContent());
        $this->assertSame([], app(ModelPolicy::class)->allowed());
    }

    public function test_refresh_and_save_remain_distinct_actions(): void
    {
        $this->localRoutes();
        $this->post('/lab/models', ['allowed' => ['anthropic:claude-sonnet-5']])->assertRedirect('/lab/models');
        $this->roster(['claude-sonnet-5', 'newly-discovered'], []);
        $this->post('/lab/models/refresh')->assertRedirect('/lab/models');
        $this->assertSame(['anthropic:claude-sonnet-5'], app(ModelPolicy::class)->allowed());
        $this->assertTrue(app(ModelCatalogue::class)->has('anthropic', 'newly-discovered'));
        $this->assertFalse(app(ModelPolicy::class)->permits('anthropic', 'newly-discovered'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'fixture-provider-secret'));
    }

    public function test_post_during_an_outage_or_missing_key_preserves_that_providers_saved_choice_after_recovery(): void
    {
        $this->localRoutes();
        $this->post('/lab/models', ['allowed' => ModelCatalogue::DEFAULT_ENABLED])->assertRedirect('/lab/models');
        $this->listingFailure = true;
        app(ModelCatalogue::class)->refresh();
        $this->post('/lab/models', ['allowed' => []])->assertRedirect('/lab/models');
        $this->assertSame(ModelCatalogue::DEFAULT_ENABLED, json_decode(DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->value('value'), true));
        $this->assertSame([], app(ModelPolicy::class)->allowed());
        $this->listingFailure = false;
        app(ModelCatalogue::class)->refresh();
        $this->assertSame(ModelCatalogue::DEFAULT_ENABLED, app(ModelPolicy::class)->allowed());

        config(['prism.providers.anthropic.api_key' => '']);
        $this->post('/lab/models', ['allowed' => []])->assertRedirect('/lab/models');
        config(['prism.providers.anthropic.api_key' => 'fixture-provider-secret']);
        $this->assertSame(['anthropic:claude-sonnet-5'], app(ModelPolicy::class)->allowed());
    }

    public function test_failed_listing_is_retried_after_sixty_seconds_and_has_a_distinct_refusal(): void
    {
        $this->listingFailure = true;
        $this->assertSame([], app(ModelCatalogue::class)->all());
        $this->assertStringContainsString('listing is unavailable', app(ModelPolicy::class)->refusal('anthropic', 'claude-sonnet-5'));
        $this->listingFailure = false;
        $this->assertSame([], app(ModelCatalogue::class)->all());
        $this->travel(61)->seconds();
        $this->assertTrue(app(ModelPolicy::class)->permits('anthropic', 'claude-sonnet-5'));
        $this->travelBack();
    }

    public function test_withdrawn_choice_stays_visible_disabled_until_the_operator_removes_it(): void
    {
        $this->localRoutes();
        $this->post('/lab/models', ['allowed' => ['anthropic:claude-sonnet-5']])->assertRedirect('/lab/models');
        $this->roster(['replacement'], []);
        app(ModelCatalogue::class)->refresh();
        $this->get('/lab/models')->assertOk()->assertInertia(fn ($page) => $page
            ->where('selection', ['anthropic:claude-sonnet-5'])
            ->where('unavailable.0.key', 'anthropic:claude-sonnet-5')
            ->where('unavailable.0.reason', 'withdrawn')->where('allowed', []));
        $this->post('/lab/models', ['allowed' => []])->assertRedirect('/lab/models');
        $this->get('/lab/models')->assertInertia(fn ($page) => $page->where('unavailable', []));
    }

    public function test_a_returned_long_model_id_can_be_explicitly_saved(): void
    {
        $this->localRoutes();
        $key = 'anthropic:future-'.str_repeat('model-', 80);
        $this->roster([substr($key, strlen('anthropic:'))], []);
        $this->post('/lab/models', ['allowed' => [$key]])->assertSessionHasNoErrors()->assertRedirect('/lab/models');
        $this->assertSame([$key], app(ModelPolicy::class)->allowed());
    }

    public function test_legacy_and_deprecated_status_is_dated_cited_and_does_not_change_defaults(): void
    {
        foreach (['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5', 'claude-fable-5'] as $id) {
            $this->assertSame(['label' => 'Legacy', 'checked_on' => '2026-11-04', 'source' => 'https://platform.claude.com/docs/en/about-claude/models/overview'], app(ModelPrices::class)->lifecycleForModel('anthropic', $id));
        }
        foreach (['gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14'] as $id) {
            $this->assertSame(['label' => 'Deprecated', 'checked_on' => '2026-11-04', 'source' => 'https://developers.openai.com/api/docs/models/gpt-4.1-mini'], app(ModelPrices::class)->lifecycleForModel('openai', $id));
        }
        $this->assertNull(app(ModelPrices::class)->lifecycleForModel('openai', 'unpriced-future'));
        $this->assertSame(['anthropic:claude-sonnet-5', 'openai:gpt-4.1-mini'], ModelCatalogue::DEFAULT_ENABLED);
        $this->assertSame(ModelCatalogue::DEFAULT_ENABLED, app(ModelPolicy::class)->allowed());
    }

    public function test_browser_fixture_matches_the_native_listing_and_inertia_pipeline(): void
    {
        $this->localRoutes();
        $this->travelTo(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
        try {
            $untrusted = '<img src=x onerror=window.modelsXss=true>';
            $this->labels[$untrusted] = '<script>window.modelsXss=true</script>';
            $long = 'future-'.str_repeat('model-', 80);
            $this->roster(['claude-fable-5-1', 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-5-5', 'unpriced-future', $untrusted, $long]);
            config(['prism.providers.perplexity' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://refused.fixture'],
                'prism.providers.replicate' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://replicate.fixture/v1'],
                'prism.providers.gemini' => ['api_key' => '', 'url' => 'https://gemini.fixture/v1beta/models'],
                'prism.providers.groq' => ['api_key' => 'fixture-provider-secret', 'url' => 'https://groq.fixture/v1']]);
            Http::fake(['https://replicate.fixture/v1/models' => Http::response(['results' => [['owner' => 'someone', 'name' => 'public']], 'next' => null]),
                'https://groq.fixture/v1/models' => Http::response(['error' => 'fixture-provider-secret'], 503)]);
            $read = function (): array {
                $response = $this->get('/lab/models');
                $response->assertOk();

                return array_intersect_key($response->inertiaProps(), array_flip(['providers', 'allowed', 'selection', 'unavailable', 'pricing']));
            };
            $initial = $read();
            $this->assertCount(6, $initial['providers']);
            $this->assertSame(ModelCatalogue::DEFAULT_ENABLED, $initial['allowed']);
            $this->roster(['claude-fable-5-1', 'unpriced-future', 'brand-new-after-refresh', $untrusted, $long]);
            app(ModelCatalogue::class)->refresh();
            $refreshed = $read();
            $this->assertSame(['openai:gpt-4.1-mini'], $refreshed['allowed']);
            $this->assertSame('withdrawn', $refreshed['unavailable'][0]['reason']);
            app(ModelPolicy::class)->allow(['anthropic:unpriced-future', 'openai:gpt-4.1-mini']);
            $this->unreachableProviders = ['anthropic'];
            app(ModelCatalogue::class)->refresh();
            $outage = $read();
            $this->assertSame(['openai:gpt-4.1-mini'], $outage['allowed']);
            $this->assertSame('unavailable', $outage['unavailable'][0]['reason']);
            $this->post('/lab/models', ['allowed' => []])->assertRedirect('/lab/models');
            $this->unreachableProviders = [];
            app(ModelCatalogue::class)->refresh();
            $recovered = $read();
            $this->assertSame(['anthropic:unpriced-future'], $recovered['selection']);
            $this->assertSame($recovered['selection'], $recovered['allowed']);
            $this->travelTo(CarbonImmutable::parse('2026-12-05T12:00:00Z'));
            $stale = $read();
            $this->assertTrue($stale['pricing']['stale']);
            $this->travelTo(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
            // A synthetic scale roster from public price data plus invented future IDs,
            // never the operator's live account roster.
            $quotedIds = array_keys(json_decode(file_get_contents(resource_path('data/openai-model-prices.json')), true, flags: JSON_THROW_ON_ERROR));
            $this->assertCount(86, $quotedIds);
            $scaleIds = array_merge($quotedIds, array_map(fn (int $number): string => sprintf('future-synthetic-%03d', $number), range(0, 55)));
            $configured = config('prism.providers');
            config(['prism.providers' => array_replace(['openai' => $configured['openai']], $configured)]);
            $this->roster(['claude-sonnet-5', 'claude-opus-4-5-20251101', 'claude-sonnet-4-5-20250929'], $scaleIds);
            app(ModelCatalogue::class)->refresh();
            app(ModelPolicy::class)->allow(ModelCatalogue::DEFAULT_ENABLED);
            $scale = $read();
            $this->assertSame('openai', $scale['providers'][0]['key']);
            $this->assertCount(142, $scale['providers'][0]['models']);
            $this->assertCount(86, array_filter($scale['providers'][0]['models'], fn (array $model): bool => $model['price'] !== null));
            $this->authenticationStatuses = ['anthropic' => 401];
            app(ModelCatalogue::class)->refresh();
            $authentication = $read();
            $this->assertSame('authentication_rejected', collect($authentication['providers'])->firstWhere('key', 'anthropic')['status']);
            $this->assertSame(['openai:gpt-4.1-mini'], $authentication['allowed']);
            $this->assertSame(ModelCatalogue::DEFAULT_ENABLED, $authentication['selection']);
            $payload = compact('initial', 'refreshed', 'outage', 'recovered', 'stale', 'scale', 'authentication');
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG);
            $this->assertStringNotContainsString('fixture-provider-secret', $json);
            $path = storage_path('framework/testing/model-catalogue-browser.actual.json');
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $json."\n");
            $fixture = base_path('tests/fixtures/model-catalogue-browser.json');
            $this->assertFileExists($fixture, 'Generate the browser golden from the retained native actual JSON.');
            $this->assertSame($payload, json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->travelBack();
        }
    }

    private function localRoutes(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN', 'fixture-csrf');
    }
}
