<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkCommentator;
use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\BenchmarkScorer;
use App\Benchmarks\LaneActivity;
use App\Benchmarks\ProofRecorder;
use App\Lab\ModelCatalogue;
use App\Lab\ModelPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

#[Group('dogfood')]
class ModelDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULTS = ['anthropic:claude-sonnet-5-5', 'openai:gpt-5.4-mini'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->detectEnvironment(fn (): string => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN', 'fixture-csrf');
        $providers = config('prism.providers');
        config(['prism.providers' => array_intersect_key($providers, array_flip(['anthropic', 'openai'])),
            'prism.providers.anthropic.api_key' => 'fixture-only',
            'prism.providers.openai.api_key' => 'fixture-only']);
        foreach (['anthropic' => ['claude-sonnet-5-5', 'claude-sonnet-5'], 'openai' => ['gpt-5.4-mini', 'gpt-4.1-mini']] as $provider => $ids) {
            Cache::put('lab.model-listing.v1.'.$provider, ['status' => 'ready', 'checked_at' => now()->toIso8601String(), 'message' => null,
                'models' => array_map(fn (string $id): array => ['id' => $id, 'name' => $id, 'created_at' => null], $ids)], 600);
        }
    }

    public function test_a_fresh_models_route_uses_only_the_new_defaults_without_saving_them(): void
    {
        $this->get('/lab/models')->assertOk()->assertInertia(fn ($page) => $page
            ->where('selection', self::DEFAULTS)->where('allowed', self::DEFAULTS));
        $this->assertDatabaseMissing('lab_settings', ['key' => 'benchmark.allowed_models']);
    }

    public function test_the_route_preserves_existing_saved_legacy_choices(): void
    {
        $legacy = ['anthropic:claude-sonnet-5', 'openai:gpt-4.1-mini'];
        $this->post('/lab/models', ['allowed' => $legacy])->assertRedirect('/lab/models');
        $stored = DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->value('value');
        $this->get('/lab/models')->assertOk()->assertInertia(fn ($page) => $page
            ->where('selection', $legacy)->where('allowed', $legacy));
        $this->assertSame($stored, DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->value('value'));
    }

    public function test_an_explicit_empty_saved_selection_stays_empty_through_the_route(): void
    {
        $this->post('/lab/models', ['allowed' => []])->assertRedirect('/lab/models');
        $this->get('/lab/models')->assertOk()->assertInertia(fn ($page) => $page
            ->where('selection', [])->where('allowed', []));
        $this->assertSame('[]', DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->value('value'));
    }

    public function test_a_withdrawn_saved_choice_is_visible_and_is_not_replaced_by_defaults(): void
    {
        $saved = ['anthropic:retired-fixture', 'openai:gpt-4.1-mini'];
        $encoded = json_encode($saved, JSON_THROW_ON_ERROR);
        DB::table('lab_settings')->insert(['key' => 'benchmark.allowed_models', 'value' => $encoded, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/lab/models')->assertOk()->assertInertia(fn ($page) => $page
            ->where('selection', $saved)->where('allowed', ['openai:gpt-4.1-mini'])
            ->where('unavailable.0.key', 'anthropic:retired-fixture'));
        $this->assertSame($encoded, DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->value('value'));
    }

    public function test_the_cheapest_enabled_default_is_gpt_5_4_mini(): void
    {
        $this->assertSame(self::DEFAULTS, app(ModelPolicy::class)->allowed());
        $this->assertSame(['provider' => 'openai', 'model' => 'gpt-5.4-mini'], app(ModelCatalogue::class)->cheapestConfigured(app(ModelPolicy::class)->allowed()));
    }

    public function test_actual_commentary_and_scoring_requests_choose_the_cheapest_new_default(): void
    {
        $fake = Prism::fake([
            TextResponseFake::make()->withText('The lane has started.'),
            TextResponseFake::make()->withText('{"dimensions":[{"name":"Correctness","score":80,"justification":"traced","cited_receipt":"manual_trace"}]}'),
        ]);
        $designer = app(BenchmarkDesigner::class);
        $spec = $designer->draft('default-consumers', 'application', 'standard', ['outcome' => 'Build a calculator.'], ['dimensions' => [
            ['name' => 'Correctness', 'weight' => 1.0, 'criteria' => 'arithmetic is correct'],
        ]], [['language' => 'php', 'harness' => 'cli', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']], ['cost' => 1]);
        $run = $designer->launch($designer->approve($designer->requestApproval($spec)));
        $lane = $run->lanes()->firstOrFail();
        app(LaneActivity::class)->record($lane, 'lane.started', 'Fixture lane started.');
        $this->assertNotNull(app(BenchmarkCommentator::class)->call($run->refresh()));
        $lane->forceFill(['status' => 'running'])->save();
        app(ProofRecorder::class)->complete($lane, [
            'spec_digest' => $spec->refresh()->digest, 'working_artifact' => 'index.php',
            'checks' => ['arithmetic' => '2+3 = 5'], 'zero_learning' => 'Check operator precedence.',
        ], [['kind' => 'manual_trace', 'payload' => ['expression' => '2+3', 'expected' => 5]]]);
        $result = app(BenchmarkScorer::class)->score($lane->refresh()->load(['receipts', 'benchmarkRun.spec']));
        $this->assertSame(80.0, $result->score);
        $fake->assertRequest(function (array $requests): void {
            $this->assertCount(2, $requests);
            foreach ($requests as $request) {
                $this->assertSame('openai', $request->provider());
                $this->assertSame('gpt-5.4-mini', $request->model());
            }
        });
        $this->assertDatabaseMissing('lab_settings', ['key' => 'benchmark.allowed_models']);
    }
}
