<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\PrismTestRegistry;
use App\Lab\PrismTestRunner;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('dogfood')]
class PerplexityResearchProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        config(['prism.providers.perplexity.api_key' => 'fixture-only', 'prism.telemetry.enabled' => true]);
        Http::preventStrayRequests();
        Log::spy();
        Exceptions::fake();
    }

    private function probe(string $kind): array
    {
        return app(PrismTestRunner::class)->run(app(PrismTestRegistry::class)->find('perplexity.research.'.$kind));
    }

    // Documented-shape fault injection, not a provider recording. Source:
    // docs.perplexity.ai/api-reference/agent-post (Response/ErrorInfo/Usage),
    // official api-platform-developers response-and-streaming.md (incomplete_details).
    private function body(string $status): array
    {
        return ['id' => 'resp_documented_shape', 'status' => $status, 'object' => 'response', 'model' => 'perplexity/sonar', 'created_at' => 1,
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'PRIVATE PARTIAL', 'annotations' => []]]], ['type' => 'search_results', 'results' => [['url' => 'https://example.test/private']]]],
            'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => ['input_tokens' => 10, 'output_tokens' => 1, 'cost' => ['total_cost' => 0.001]]];
    }

    public function test_no_key_is_an_explicit_skip_and_never_a_pass(): void
    {
        config(['prism.providers.perplexity.api_key' => '']);
        $r = $this->probe('completed');
        $this->assertFalse($r['passed']);
        $this->assertSame('SKIPPED: no key', $r['note']);
        Http::assertNothingSent();
    }

    public function test_live_path_is_refused_outside_local_before_provider_dispatch(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse($this->probe('completed')['passed']);
        Http::assertNothingSent();
    }

    public function test_completed_control_requires_citations_usage_and_request_id_and_redacts_content(): void
    {
        Http::fake(['*' => Http::response($this->body('completed'), 200, ['X-Request-ID' => 'req_not_logged'])]);
        $r = $this->probe('completed');
        $this->assertTrue($r['passed']);
        $this->assertSame('PASS', $r['verdict']);
        $this->assertSame(1, $r['metrics']['citations_count']);
        $this->assertTrue($r['metrics']['request_id_present']);
        $this->assertTrue(config('prism.telemetry.enabled'));
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertStringNotContainsString('req_not_logged', json_encode($r));
        Http::assertSent(fn ($req) => $req['max_steps'] === 6 && $req['max_output_tokens'] === 2048 && $req['effort'] === 'medium');
        Exceptions::assertNothingReported();
        Log::shouldNotHaveReceived('error');
    }

    public function test_incomplete_preserves_sanitized_reason_without_success_substitution(): void
    {
        Http::fake(['*' => Http::response($this->body('incomplete'), 200, ['X-Request-ID' => 'req_incomplete'])]);
        $r = $this->probe('incomplete');
        $this->assertTrue($r['passed']);
        $this->assertSame('incomplete', $r['metrics']['status']);
        $this->assertSame('max_output_tokens', $r['metrics']['reason']);
        $this->assertTrue($r['metrics']['partial_output_present']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        Http::assertSent(fn ($req) => $req['max_steps'] === 1 && $req['max_output_tokens'] === 1);
    }

    public function test_completed_provider_does_not_pass_the_incomplete_or_failed_observation(): void
    {
        Http::fake(['*' => Http::response($this->body('completed'), 200, ['X-Request-ID' => 'req_completed'])]);
        foreach (['incomplete', 'failed'] as $kind) {
            $r = $this->probe($kind);
            $this->assertFalse($r['passed']);
            $this->assertSame('UNMEASURED', $r['verdict']);
        }
    }

    public function test_documented_failed_shape_propagates_without_logging_its_message(): void
    {
        $body = $this->body('failed');
        $body['error'] = ['message' => 'PRIVATE PROVIDER MESSAGE', 'code' => 'provider_error', 'type' => 'provider_error'];
        Http::fake(['*' => Http::response($body, 200, ['X-Request-ID' => 'req_failed'])]);
        $r = $this->probe('failed');
        $this->assertTrue($r['passed']);
        $this->assertTrue($r['metrics']['provider_message_present']);
        $this->assertTrue($r['metrics']['provider_code_present']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        Exceptions::assertNothingReported();
        Log::shouldNotHaveReceived('error');
    }

    public function test_current_recency_plus_date_composition_is_exercised_on_both_models(): void
    {
        Http::fake(['*' => Http::response($this->body('completed'))]);
        foreach (['filters-deep', 'filters-pro'] as $kind) {
            $this->assertTrue($this->probe($kind)['passed']);
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => isset($req['tools'][0]['filters']['search_recency_filter'], $req['tools'][0]['filters']['search_after_date_filter']));
    }

    public function test_transport_failure_is_failed_redacted_and_never_reported(): void
    {
        Http::fake(['*' => Http::failedConnection('PRIVATE transport message')]);
        $r = $this->probe('completed');
        $this->assertFalse($r['passed']);
        $this->assertSame('FAIL', $r['verdict']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        Exceptions::assertNothingReported();
        Log::shouldNotHaveReceived('error');
    }

    public function test_real_observed_quota_message_is_a_skip_and_never_a_live_pass(): void
    {
        // Actual message quoted by the maintainer from the real Lab UI,
        // AgentInbox3200,2026-10-30. Envelope is a documented-shape control;
        // the raw HTTP envelope was not available and is not called recorded.
        Http::fake(['*' => Http::response(['error' => ['message' => 'You exceeded your current quota, add credits at https://console.perplexity.ai/project/billing.']], 401)]);
        $r = $this->probe('completed');
        $this->assertFalse($r['passed']);
        $this->assertSame('SKIPPED', $r['verdict']);
        $this->assertSame('quota_exhausted', $r['metrics']['reason']);
        $this->assertStringNotContainsString('project/billing', json_encode($r));
        Exceptions::assertNothingReported();
    }
}
