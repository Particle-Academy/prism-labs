<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\AnthropicBilling;
use App\Billing\BillingReconciler;
use App\Billing\OpenAiBilling;
use App\Billing\PriceMismatches;
use App\Billing\UsageRecorder;
use App\Drift\ProviderWatchReader;
use App\Drift\UpstreamDrift;
use App\Drift\WatcherGit;
use App\Http\Controllers\Lab\DriftController;
use App\Jobs\ReconcileProviderBillingJob;
use App\Providers\PrismLabServiceProvider;
use App\Telemetry\PrismTelemetrySubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Tests\TestCase;

/**
 * G-291: recorded usage vs provider billing, and configured vs catalogue
 * prices. Fake keys and Http::fake only; no request leaves the process.
 */
final class BillingCalibrationTest extends TestCase
{
    use RefreshDatabase;

    // Fixture strings, shaped like the real thing so a leak is recognisable.
    private const OPENAI_KEY = 'sk-admin-FIXTURE-g291-openai-never-real';

    private const ANTHROPIC_KEY = 'sk-ant-admin01-FIXTURE-g291-anthropic-never-real';

    private const OPENAI_USAGE = 'https://api.openai.com/v1/organization/usage/completions*';

    private const OPENAI_COSTS = 'https://api.openai.com/v1/organization/costs*';

    private const ANTHROPIC_USAGE = 'https://api.anthropic.com/v1/organizations/analytics/usage*';

    private const ANTHROPIC_COST = 'https://api.anthropic.com/v1/organizations/analytics/cost*';

    /** The last closed day past grace, for the frozen clock below. */
    private const DAY = '2026-11-13';

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T15:00:00Z'));
        config([
            'lab.billing.openai_admin_key' => self::OPENAI_KEY,
            'lab.billing.anthropic_admin_key' => self::ANTHROPIC_KEY,
            'lab.billing.openai_api_key_ids' => ['key_lab1', 'key_lab2'],
            'lab.billing.openai_project_ids' => ['proj_lab'],
            'lab.billing.anthropic_api_key_ids' => ['apikey_lab'],
            'lab.billing.grace_hours' => 24,
            'lab.billing.lookback_days' => 14,
            'lab.billing.threshold_percent' => 2.0,
            'lab.billing.floor_tokens' => 1000,
            'lab.billing.floor_usd' => 0.05,
            'prism-harness.pricing' => [],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- seam

    public function test_a_real_prism_call_is_recorded_through_the_telemetry_seam(): void
    {
        config([
            'prism.providers.anthropic.api_key' => 'fixture-provider-key',
            'prism-harness.pricing' => ['anthropic' => ['claude-sonnet-5' => [
                'inputPerMTok' => 2.0, 'outputPerMTok' => 10.0, 'cacheReadPerMTok' => 0.2, 'cacheWritePerMTok' => 2.5,
            ]]],
        ]);
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_fixture', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => 'hi']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_creation_input_tokens' => 5, 'cache_read_input_tokens' => 30],
        ])]);

        // The Lab's real local wiring (as ApprovesBenchmarks does): its boot()
        // subscribes the telemetry subscriber. register() — which also forces
        // telemetry on — builds an OTLP exporter, so that one flag is set here.
        config(['prism.telemetry.enabled' => true, 'prism-browser.sidecar.token' => str_repeat('owned-g291-fixture-', 2)]);
        $provider = new PrismLabServiceProvider($this->app);
        $provider->bindLocalServices();
        $provider->boot();

        // Entered where every Lab caller enters: Prism itself, not the recorder.
        Prism::text()->using('anthropic', 'claude-sonnet-5')->withPrompt('hello')->asText();

        $rows = DB::table('provider_usage_records')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('anthropic', $row->provider);
        $this->assertSame('claude-sonnet-5', $row->model);
        // The UTC day the call STARTED, from Prism's own wall-clock start time
        // (the frozen test clock does not move microtime).
        $this->assertSame(gmdate('Y-m-d'), substr((string) $row->day, 0, 10));
        $this->assertSame([100, 30, 5, 20, 1], [(int) $row->input_tokens, (int) $row->cached_input_tokens,
            (int) $row->cache_write_tokens, (int) $row->output_tokens, (int) $row->call_count]);
        // Harness's own rule: (100*2 + 20*10 + 5*2.5 + 30*0.2) / 1e6.
        $this->assertEqualsWithDelta(0.0004185, (float) $row->harness_cost_usd, 1e-9);
    }

    public function test_an_unpriced_model_records_a_null_cost_never_zero(): void
    {
        $context = $this->context('openai', 'gpt-unpriced');
        app(PrismTelemetrySubscriber::class)->completed(new GenerationCompleted($context, 1.0, null, new Usage(10, 5)));

        $row = DB::table('provider_usage_records')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->harness_cost_usd);
    }

    public function test_a_failed_attempt_records_reported_usage_once(): void
    {
        $class = 'Prism\\Prism\\ValueObjects\\StreamFailureUsage';
        $errored = 'Prism\\Prism\\Events\\Telemetry\\GenerationErrored';
        if (! class_exists($class) || ! class_exists($errored)) {
            $this->markTestSkipped('Requires core failure usage.');
        }
        $context = $this->context('anthropic', 'claude-sonnet-5');
        $report = new $class(new Usage(11, 3, cacheReadInputTokens: 99), 1, ['prompt_tokens', 'completion_tokens']);
        $subscriber = app(PrismTelemetrySubscriber::class);
        $subscriber->errored(new $errored($context, 4.0, $report));
        $subscriber->failed(new GenerationFailed($context, 4.0, new RuntimeException('boom'), $report));

        $rows = DB::table('provider_usage_records')->get();
        $this->assertCount(1, $rows, 'a doubled failure notification is one call');
        // cache_read was not a REPORTED field, so it is not counted.
        $this->assertSame([11, 0, 3, 'failed', 2], [(int) $rows[0]->input_tokens, (int) $rows[0]->cached_input_tokens,
            (int) $rows[0]->output_tokens, $rows[0]->outcome, (int) $rows[0]->call_count]);
    }

    public function test_step_events_count_provider_requests_in_a_generation(): void
    {
        $context = $this->context('openai', 'gpt-x');
        $recorder = app(UsageRecorder::class);
        $recorder->step($context);
        $recorder->step($context);
        $recorder->step($context);
        $recorder->completed($context, new Usage(1, 1));

        $this->assertSame(3, (int) DB::table('provider_usage_records')->value('call_count'));
    }

    // ------------------------------------------------------------- clients

    public function test_the_openai_client_parses_paginated_usage_and_costs(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (str_contains($request->url(), '/organization/usage/completions')) {
                return ($query['page'] ?? null) === 'cursor-2'
                    ? Http::response($this->openAiUsagePage([[self::DAY, 'gpt-x', 2000, 500, 300, 2]], false))
                    : Http::response($this->openAiUsagePage([[self::DAY, 'gpt-x', 1000, 200, 100, 1], ['2026-11-12', 'gpt-y', 50, 0, 10, 1]], true, 'cursor-2'));
            }
            if (str_contains($request->url(), '/organization/costs')) {
                return ($query['page'] ?? null) === 'c2'
                    ? Http::response($this->openAiCostPage([[self::DAY, 0.25]], false))
                    : Http::response($this->openAiCostPage([[self::DAY, 1.5], ['2026-11-12', 0.01]], true, 'c2'));
            }

            return Http::response([], 404);
        });

        $period = app(OpenAiBilling::class)->fetch(CarbonImmutable::parse('2026-11-12', 'UTC'), CarbonImmutable::parse('2026-11-14', 'UTC'));

        // input_tokens INCLUDES cached: (1000-200) + (2000-500) uncached.
        $this->assertSame(['input' => 2300, 'cached_input' => 700, 'cache_write' => 0, 'output' => 400, 'audio' => 0, 'requests' => 3],
            $period->tokens[self::DAY]['gpt-x']);
        $this->assertSame(10, $period->tokens['2026-11-12']['gpt-y']['output']);
        $this->assertEqualsWithDelta(1.75, $period->cost[self::DAY]['*'], 1e-9);
        $this->assertFalse($period->costByModel, 'OpenAI costs cannot be grouped by model');

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(4, $sent, 'two pages from each endpoint');
        foreach ($sent as $request) {
            $this->assertSame('api.openai.com', parse_url($request->url(), PHP_URL_HOST));
            $this->assertSame('Bearer '.self::OPENAI_KEY, $request->header('Authorization')[0]);
        }
        $usageUrl = $sent->first(fn (Request $r) => str_contains($r->url(), 'usage/completions'))->url();
        $this->assertStringContainsString('api_key_ids=key_lab1&api_key_ids=key_lab2', $usageUrl);
        $this->assertStringContainsString('group_by=model', $usageUrl);
        $this->assertStringContainsString('bucket_width=1d', $usageUrl);
        $costUrl = $sent->first(fn (Request $r) => str_contains($r->url(), '/costs'))->url();
        $this->assertStringContainsString('project_ids=proj_lab', $costUrl);
    }

    public function test_the_anthropic_client_parses_paginated_usage_and_cost_in_cents(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $second = ($query['page'] ?? null) === 'page_2';
            if (str_contains($request->url(), '/analytics/usage')) {
                return Http::response($this->anthropicUsagePage($second
                    ? [[self::DAY, 'claude-sonnet-5', 10, 1, 2, 3, 4, 1]]
                    : [[self::DAY, 'claude-sonnet-5', 100, 10, 20, 30, 40, 2], [self::DAY, null, 7, 0, 0, 0, 0, 1]], ! $second, 'page_2'));
            }
            if (str_contains($request->url(), '/analytics/cost')) {
                return Http::response($this->anthropicCostPage($second
                    ? [[self::DAY, 'claude-sonnet-5', '50.000000']]
                    : [[self::DAY, 'claude-sonnet-5', '41280.000000']], ! $second, 'page_2'));
            }

            return Http::response([], 404);
        });

        $period = app(AnthropicBilling::class)->fetch(CarbonImmutable::parse(self::DAY, 'UTC'), CarbonImmutable::parse('2026-11-14', 'UTC'));

        $this->assertSame(['input' => 110, 'cached_input' => 33, 'cache_write' => 33, 'output' => 44, 'audio' => 0, 'requests' => 3],
            $period->tokens[self::DAY]['claude-sonnet-5']);
        $this->assertArrayHasKey('(no model)', $period->tokens[self::DAY], 'a null model is kept, not dropped');
        // "41280.000000" cents is $412.80.
        $this->assertEqualsWithDelta(413.30, $period->cost[self::DAY]['claude-sonnet-5'], 1e-9);
        $this->assertTrue($period->costByModel);
        $this->assertSame('2026-11-15T12:00:00+00:00', $period->freshThrough?->toIso8601String());

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(4, $sent);
        foreach ($sent as $request) {
            $this->assertSame('api.anthropic.com', parse_url($request->url(), PHP_URL_HOST));
            $this->assertSame(self::ANTHROPIC_KEY, $request->header('x-api-key')[0]);
            $this->assertSame('analytics-usage-cost-2026-08-24', $request->header('anthropic-beta')[0]);
            $this->assertNotEmpty($request->header('anthropic-version')[0]);
            $this->assertSame([], $request->header('Authorization'));
        }
        $url = urldecode($sent->first()->url());
        $this->assertStringContainsString('api_key_ids[]=apikey_lab', $url);
        $this->assertStringContainsString('group_by[]=model', $url);
        $this->assertStringContainsString('time_bucket_width=1d', $url);
        $this->assertStringContainsString('starting_at=2026-11-13T00:00:00Z', $url);
        $this->assertStringContainsString('ending_at=2026-11-14T00:00:00Z', $url);
    }

    // ------------------------------------------------------ reconciliation

    public function test_reconciliation_flags_over_threshold_and_ignores_within_threshold(): void
    {
        $this->disable('openai');
        // claude-sonnet-5: recorded 100k, billed 110k tokens (10%, 10k over the floor) -> flagged.
        // claude-opus-5:   recorded 100k, billed 101k (1%) -> ok.
        $this->record('anthropic', 'claude-sonnet-5', 60000, 0, 0, 40000, 1.00);
        $this->record('anthropic', 'claude-opus-5', 50000, 20000, 0, 30000, 2.00);
        $this->fakeAnthropic(
            [[self::DAY, 'claude-sonnet-5', 70000, 0, 0, 0, 40000, 5], [self::DAY, 'claude-opus-5', 51000, 20000, 0, 0, 30000, 5]],
            // $1.01 billed vs $1.00 recorded is 1%: ok. $2.50 vs $2.00 is 25% and over the $0.05 floor: flagged.
            [[self::DAY, 'claude-sonnet-5', '101.000000'], [self::DAY, 'claude-opus-5', '250.000000']],
        );

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        $this->assertSame('flagged', $this->verdict('anthropic', 'claude-sonnet-5', 'tokens'));
        $this->assertSame('ok', $this->verdict('anthropic', 'claude-opus-5', 'tokens'));
        $this->assertSame('ok', $this->verdict('anthropic', 'claude-sonnet-5', 'cost'));
        $this->assertSame('flagged', $this->verdict('anthropic', 'claude-opus-5', 'cost'));
        $this->assertSame('flagged', $this->verdict('anthropic', '*', 'tokens'), '11k of 211k is over 2% and the floor');

        // A big percentage under the absolute floor is not flagged.
        $this->assertSame('ok', BillingReconciler::compare(10, 30, 1000)['status']);
        // A small percentage over the floor is not flagged either.
        $this->assertSame('ok', BillingReconciler::compare(1_000_000, 1_015_000, 1000)['status']);
        // Billed something, recorded nothing: flagged (Lab calls the seam missed).
        $this->assertSame('flagged', BillingReconciler::compare(0, 5000, 1000)['status']);
        // Recorded something the provider never billed: infinitely off, flagged.
        $this->assertSame('flagged', BillingReconciler::compare(5000, 0, 1000)['status']);
        $this->assertNull(BillingReconciler::compare(5000, 0, 1000)['delta_percent']);
    }

    public function test_a_provider_without_an_admin_key_is_not_configured_never_ok(): void
    {
        config(['lab.billing.openai_admin_key' => null, 'lab.billing.anthropic_admin_key' => '  ']);
        $this->record('anthropic', 'claude-sonnet-5', 1, 0, 0, 1, 0.0);

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        Http::assertNothingSent();
        $statuses = DB::table('billing_reconciliations')->pluck('status')->unique()->values()->all();
        $this->assertSame(['not_configured'], $statuses);
        $this->assertSame(14 * 2 * 2, DB::table('billing_reconciliations')->count(), 'every day, both metrics, both providers');
        $this->assertSame(0, DB::table('billing_reconciliations')->whereNotNull('billed')->count());
        $this->assertSame('admin_key_missing', DB::table('billing_reconciliations')->where('provider', 'openai')->value('reason'));
    }

    public function test_without_key_id_scope_a_provider_is_not_configured(): void
    {
        config(['lab.billing.anthropic_api_key_ids' => []]);
        $this->disable('openai');

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        Http::assertNothingSent();
        $this->assertSame('api_key_ids_missing', DB::table('billing_reconciliations')->where('provider', 'anthropic')->value('reason'));
    }

    public function test_a_failed_fetch_is_unknown_never_zero(): void
    {
        $this->disable('anthropic');
        $this->record('openai', 'gpt-x', 500, 0, 0, 500, 0.01);
        Http::fake([self::OPENAI_USAGE => Http::response(['error' => 'down'], 503), self::OPENAI_COSTS => Http::response(['error' => 'down'], 500)]);

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        $rows = DB::table('billing_reconciliations')->where('provider', 'openai')->whereDate('day', self::DAY)->get();
        $this->assertSame(['unknown'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(['http_503', 'http_500'], $rows->sortBy('metric', SORT_STRING, true)->pluck('reason')->values()->all());
        $this->assertSame(0, $rows->whereNotNull('billed')->count(), 'no billed figure was read, so none is stored');
    }

    public function test_openai_cost_without_project_scope_is_not_configured_while_tokens_compare(): void
    {
        $this->disable('anthropic');
        config(['lab.billing.openai_project_ids' => []]);
        $this->record('openai', 'gpt-x', 800, 200, 0, 400, 0.01);
        Http::fake([self::OPENAI_USAGE => Http::response($this->openAiUsagePage([[self::DAY, 'gpt-x', 1000, 200, 400, 1]], false))]);

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        $this->assertSame('ok', $this->verdict('openai', '*', 'tokens'));
        $this->assertSame('not_configured', $this->verdict('openai', '*', 'cost'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/costs'));
    }

    public function test_an_anthropic_day_past_the_refresh_point_is_unknown(): void
    {
        $this->disable('openai');
        $this->fakeAnthropic([[self::DAY, 'claude-sonnet-5', 1, 0, 0, 0, 1, 1]], [[self::DAY, 'claude-sonnet-5', '1.000000']], refreshedAt: '2026-11-13T20:00:00Z');

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        $this->assertSame('unknown', $this->verdict('anthropic', '*', 'tokens'));
        $this->assertSame('billing_not_refreshed', DB::table('billing_reconciliations')->where('provider', 'anthropic')->whereDate('day', self::DAY)->value('reason'));
        $this->assertSame('ok', $this->verdict('anthropic', '*', 'tokens', '2026-11-12'), 'an earlier, refreshed day still compares');
    }

    public function test_recorded_cost_that_harness_could_not_price_is_unknown_not_compared(): void
    {
        $this->disable('openai');
        $this->record('anthropic', 'claude-sonnet-5', 100, 0, 0, 100, null);
        $this->fakeAnthropic([[self::DAY, 'claude-sonnet-5', 100, 0, 0, 0, 100, 1]], [[self::DAY, 'claude-sonnet-5', '900.000000']]);

        app(BillingReconciler::class)->run(CarbonImmutable::now());

        $this->assertSame('unknown', $this->verdict('anthropic', 'claude-sonnet-5', 'cost'));
        $this->assertSame('ok', $this->verdict('anthropic', 'claude-sonnet-5', 'tokens'));
    }

    public function test_only_closed_days_past_grace_are_reconciled(): void
    {
        $days = BillingReconciler::days(CarbonImmutable::parse('2026-11-15T15:00:00Z'));
        $this->assertSame('2026-11-13', end($days), '11-14 closes at midnight and is still inside its 24h grace');
        $this->assertCount(14, $days);
        $this->assertSame('2026-11-14', BillingReconciler::days(CarbonImmutable::parse('2026-11-16T00:00:00Z'))[13]);
    }

    // ------------------------------------------------------------- secrets

    public function test_admin_keys_never_reach_props_logs_rows_exceptions_or_the_job_payload(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });
        $leaky = fn (string $key) => ['error' => ['message' => 'Invalid key '.$key, 'echo' => $key]];
        // Hostile upstreams: error bodies and an exception message that ECHO the key.
        Http::fake(function (Request $request) use ($leaky) {
            if (str_contains($request->url(), 'api.openai.com/v1/organization/costs')) {
                throw new ConnectionException('cURL error for '.self::OPENAI_KEY);
            }

            return str_contains($request->url(), 'api.openai.com')
                ? Http::response($leaky(self::OPENAI_KEY), 401)
                : Http::response($leaky(self::ANTHROPIC_KEY), 403);
        });

        $job = new ReconcileProviderBillingJob;
        $payload = serialize($job);
        dispatch($job);

        // Positive control: each key WAS used, in its own provider's header only.
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.openai.com/') && $r->header('Authorization') === ['Bearer '.self::OPENAI_KEY]);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.anthropic.com/') && $r->header('x-api-key') === [self::ANTHROPIC_KEY]);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.openai.com/') && str_contains(json_encode($r->headers()), self::ANTHROPIC_KEY));
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.anthropic.com/') && str_contains(json_encode($r->headers()), self::OPENAI_KEY));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::OPENAI_KEY) || str_contains($r->url(), self::ANTHROPIC_KEY) || str_contains($r->body(), 'sk-'));

        $this->assertGreaterThan(0, DB::table('billing_reconciliations')->where('status', 'unknown')->count());
        $props = json_encode($this->driftProps(), JSON_THROW_ON_ERROR);
        $rows = json_encode(DB::table('billing_reconciliations')->get(), JSON_THROW_ON_ERROR);
        $exception = '';
        try {
            app(OpenAiBilling::class)->fetch(CarbonImmutable::parse(self::DAY, 'UTC'), CarbonImmutable::parse('2026-11-14', 'UTC'));
        } catch (\Throwable $thrown) {
            $exception = $thrown->getMessage().$thrown->getTraceAsString();
        }
        $period = app(OpenAiBilling::class)->fetch(CarbonImmutable::parse(self::DAY, 'UTC'), CarbonImmutable::parse('2026-11-14', 'UTC'));
        $exception .= json_encode([$period->tokensFailure, $period->costReason]);
        $this->assertSame('http_401', $period->tokensFailure);
        $this->assertSame('connection_failed', $period->costReason);

        foreach ([self::OPENAI_KEY, self::ANTHROPIC_KEY, 'FIXTURE-g291'] as $secret) {
            $this->assertStringNotContainsString($secret, $props, 'page props');
            $this->assertStringNotContainsString($secret, $rows, 'stored reconciliation rows');
            $this->assertStringNotContainsString($secret, $payload, 'queued job payload');
            $this->assertStringNotContainsString($secret, implode("\n", $logged), 'logs');
            $this->assertStringNotContainsString($secret, $exception, 'exceptions');
            $this->assertStringNotContainsString($secret, print_r(app(OpenAiBilling::class), true), 'a dumped client');
        }
    }

    // -------------------------------------------------------------- prices

    public function test_configured_harness_rates_are_compared_with_the_catalogue(): void
    {
        $watch = $this->watchFixture();
        $this->prices($watch, 'anthropic', [
            'claude-match' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5],
            'claude-moved' => ['input' => 2.5, 'output' => 10.0, 'cache_read' => null, 'cache_write' => null],
            'claude-no-cache-rate' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_write' => null],
        ]);
        config(['prism-harness.pricing' => ['anthropic' => [
            'claude-match' => ['inputPerMTok' => 2, 'outputPerMTok' => 10.0, 'cacheReadPerMTok' => 0.2, 'cacheWritePerMTok' => 2.5],
            'claude-moved' => ['inputPerMTok' => 3.0, 'outputPerMTok' => 10.0, 'cacheReadPerMTok' => 0.3],
            // Omitted cache-read: Harness charges it at the input price, 10x the catalogue.
            'claude-no-cache-rate' => ['inputPerMTok' => 3.0, 'outputPerMTok' => 15.0],
            'claude-unlisted' => ['inputPerMTok' => 1.0, 'outputPerMTok' => 1.0],
        ]]]);

        $result = app(PriceMismatches::class)->read($watch);
        $tiles = collect($result['tiles'])->where('source', 'harness')->keyBy('model');

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame(4, $result['harnessConfiguredModels']);
        $this->assertArrayNotHasKey('claude-match', $tiles->all(), '2 and 2.0 are the same price');
        $this->assertSame([['field' => 'input', 'configured' => 3.0, 'catalogue' => 2.5]], $tiles['claude-moved']['fields'],
            'a field the catalogue does not publish is not compared');
        $this->assertSame([['field' => 'cache_read', 'configured' => 3.0, 'catalogue' => 0.3]], $tiles['claude-no-cache-rate']['fields']);
        $this->assertSame('not_in_catalogue', $tiles['claude-unlisted']['reason']);
    }

    public function test_price_check_is_unknown_when_the_watcher_has_no_price_snapshot(): void
    {
        $result = app(PriceMismatches::class)->read($this->watchFixture());

        $this->assertSame('unknown', $result['status'], 'the committed fixture predates price recording');
        $this->assertSame([], $result['tiles']);
        $this->assertContains('anthropic', $result['unreadableProviders']);
    }

    // --------------------------------------------------------------- panel

    public function test_the_drift_panel_props_colour_days_and_badge_missing_data(): void
    {
        $this->disable('openai');
        config(['lab.billing.lookback_days' => 3]);
        // 11-13 flagged cost, 11-12 ok, 11-11 approaching (1.5% and $0.30 off).
        $this->record('anthropic', 'claude-sonnet-5', 100000, 0, 0, 0, 10.00);
        $this->record('anthropic', 'claude-sonnet-5', 100000, 0, 0, 0, 10.00, '2026-11-12');
        $this->record('anthropic', 'claude-sonnet-5', 100000, 0, 0, 0, 20.00, '2026-11-11');
        $this->fakeAnthropic(
            [[self::DAY, 'claude-sonnet-5', 100000, 0, 0, 0, 0, 1], ['2026-11-12', 'claude-sonnet-5', 100000, 0, 0, 0, 0, 1], ['2026-11-11', 'claude-sonnet-5', 100000, 0, 0, 0, 0, 1]],
            [[self::DAY, 'claude-sonnet-5', '1300.000000'], ['2026-11-12', 'claude-sonnet-5', '1000.000000'], ['2026-11-11', 'claude-sonnet-5', '2030.000000']],
        );
        app(BillingReconciler::class)->run(CarbonImmutable::now());

        $props = $this->driftProps();
        $billing = collect($props['billing']['providers'])->keyBy('provider');

        $this->assertSame('not_configured', $billing['openai']['status']);
        $this->assertSame('admin_key_missing', $billing['openai']['reason']);
        $this->assertSame(['not_configured'], collect($billing['openai']['days'])->pluck('band')->unique()->values()->all());

        $days = collect($billing['anthropic']['days'])->keyBy('day');
        $this->assertSame(['2026-11-11', '2026-11-12', '2026-11-13'], $days->keys()->all());
        $this->assertSame('red', $days[self::DAY]['band']);
        $this->assertSame(10.0, $days[self::DAY]['cost']['recorded']);
        $this->assertSame(13.0, $days[self::DAY]['cost']['billed']);
        $this->assertSame('green', $days['2026-11-12']['band']);
        $this->assertSame('amber', $days['2026-11-11']['band']);
        $this->assertSame('red', $billing['anthropic']['status']);

        // A day the job has not reconciled is unknown, with no figures.
        DB::table('billing_reconciliations')->whereDate('day', '2026-11-12')->delete();
        $day = collect(collect($this->driftProps()['billing']['providers'])->firstWhere('provider', 'anthropic')['days'])->firstWhere('day', '2026-11-12');
        $this->assertSame('unknown', $day['band']);
        $this->assertSame('not_reconciled', $day['cost']['reason']);
        $this->assertNull($day['cost']['billed']);

        // Change rows carry the watcher's detail next to the subject.
        $anthropic = collect($props['providers'])->firstWhere('name', 'anthropic');
        $this->assertSame('new model exposed by the provider', $anthropic['changes'][0]['detail']);
        $this->assertArrayHasKey('prices', $props);
    }

    // ------------------------------------------------------------- helpers

    private function context(string $provider, string $model): TelemetryContext
    {
        return new TelemetryContext((string) Str::uuid(), TelemetryOperation::Text, $provider, $model, microtime(true));
    }

    private function disable(string $provider): void
    {
        config(["lab.billing.{$provider}_admin_key" => null]);
    }

    private function record(string $provider, string $model, int $input, int $cached, int $write, int $output, ?float $cost, string $day = self::DAY): void
    {
        DB::table('provider_usage_records')->insert([
            'trace_id' => (string) Str::uuid(), 'provider' => $provider, 'model' => $model, 'day' => $day, 'outcome' => 'completed',
            'input_tokens' => $input, 'cached_input_tokens' => $cached, 'cache_write_tokens' => $write, 'output_tokens' => $output,
            'harness_cost_usd' => $cost, 'call_count' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function verdict(string $provider, string $model, string $metric, string $day = self::DAY): ?string
    {
        return DB::table('billing_reconciliations')->where(['provider' => $provider, 'model' => $model, 'metric' => $metric])
            ->whereDate('day', $day)->value('status');
    }

    /** @return array<string, mixed> */
    private function driftProps(): array
    {
        $watch = $this->watchFixture();
        config(['lab.provider_watch_path' => $watch]);
        $this->app->instance(WatcherGit::class, new class extends WatcherGit
        {
            public function __construct() {}

            public function lastStateCommit(string $path): ?CarbonImmutable
            {
                return null;
            }
        });
        // The upstream panel is not under test; answer it with failures (unknown).
        Http::fake(['https://api.github.com/*' => Http::response([], 503)]);

        return DriftController::props(app(ProviderWatchReader::class), app(UpstreamDrift::class));
    }

    private function watchFixture(): string
    {
        $dir = storage_path('framework/testing/billing-'.bin2hex(random_bytes(6)));
        File::copyDirectory(base_path('tests/fixtures/provider-watch'), $dir);
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** @param array<string, array<string, float|null>> $prices */
    private function prices(string $watch, string $provider, array $prices): void
    {
        $file = $watch.'/state/'.$provider.'.json';
        $state = json_decode(File::get($file), true);
        $state['sources']['models-dev']['data']['prices'] = $prices;
        File::put($file, json_encode($state));
    }

    /** @param list<array{string, string, int, int, int, int}> $rows day, model, input (incl cached), cached, output, requests */
    private function openAiUsagePage(array $rows, bool $more, ?string $next = null): array
    {
        $buckets = [];
        foreach ($rows as [$day, $model, $input, $cached, $output, $requests]) {
            $start = CarbonImmutable::parse($day, 'UTC')->getTimestamp();
            $buckets[] = ['object' => 'bucket', 'start_time' => $start, 'end_time' => $start + 86400, 'results' => [[
                'object' => 'organization.usage.completions.result', 'input_tokens' => $input, 'input_cached_tokens' => $cached,
                'output_tokens' => $output, 'input_audio_tokens' => 0, 'output_audio_tokens' => 0, 'num_model_requests' => $requests,
                'model' => $model, 'api_key_id' => null, 'project_id' => null, 'user_id' => null, 'batch' => null,
            ]]];
        }

        return ['object' => 'page', 'data' => $buckets, 'has_more' => $more, 'next_page' => $next];
    }

    /** @param list<array{string, float}> $rows */
    private function openAiCostPage(array $rows, bool $more, ?string $next = null): array
    {
        $buckets = [];
        foreach ($rows as [$day, $usd]) {
            $start = CarbonImmutable::parse($day, 'UTC')->getTimestamp();
            $buckets[] = ['object' => 'bucket', 'start_time' => $start, 'end_time' => $start + 86400, 'results' => [[
                'object' => 'organization.costs.result', 'amount' => ['value' => $usd, 'currency' => 'usd'], 'line_item' => null, 'project_id' => null,
            ]]];
        }

        return ['object' => 'page', 'data' => $buckets, 'has_more' => $more, 'next_page' => $next];
    }

    /** @param list<array{string, ?string, int, int, int, int, int, int}> $rows day, model, uncached, 5m, 1h, cache read, output, requests */
    private function anthropicUsagePage(array $rows, bool $more, ?string $next = null, string $refreshedAt = '2026-11-15T12:00:00Z'): array
    {
        $data = [];
        foreach ($rows as [$day, $model, $uncached, $w5, $w1, $read, $output, $requests]) {
            $data[] = ['starting_at' => $day.'T00:00:00Z', 'ending_at' => CarbonImmutable::parse($day, 'UTC')->addDay()->format('Y-m-d').'T00:00:00Z',
                'group' => ['model' => $model, 'api_key_id' => null, 'workspace_id' => null],
                'usage' => ['uncached_input_tokens' => $uncached, 'cache_creation' => ['ephemeral_1h_input_tokens' => $w1, 'ephemeral_5m_input_tokens' => $w5],
                    'cache_read_input_tokens' => $read, 'output_tokens' => $output, 'total_tokens' => $uncached + $w1 + $w5 + $read + $output,
                    'server_tool_use' => ['web_search_requests' => 0], 'requests' => $requests]];
        }

        return ['data' => $data, 'has_more' => $more, 'next_page' => $more ? $next : null, 'data_refreshed_at' => $refreshedAt];
    }

    /** @param list<array{string, string, string}> $rows day, model, cents */
    private function anthropicCostPage(array $rows, bool $more, ?string $next = null, string $refreshedAt = '2026-11-15T12:00:00Z'): array
    {
        $data = [];
        foreach ($rows as [$day, $model, $cents]) {
            $data[] = ['starting_at' => $day.'T00:00:00Z', 'ending_at' => CarbonImmutable::parse($day, 'UTC')->addDay()->format('Y-m-d').'T00:00:00Z',
                'group' => ['model' => $model], 'cost' => ['currency' => 'USD', 'amount' => $cents, 'list_amount' => $cents, 'requests' => 1]];
        }

        return ['data' => $data, 'has_more' => $more, 'next_page' => $more ? $next : null, 'data_refreshed_at' => $refreshedAt];
    }

    private function fakeAnthropic(array $usage, array $cost, string $refreshedAt = '2026-11-15T12:00:00Z'): void
    {
        Http::fake([
            self::ANTHROPIC_USAGE => Http::response($this->anthropicUsagePage($usage, false, null, $refreshedAt)),
            self::ANTHROPIC_COST => Http::response($this->anthropicCostPage($cost, false, null, $refreshedAt)),
        ]);
    }
}
