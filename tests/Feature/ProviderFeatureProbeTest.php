<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Support\HostResolver;
use Tests\TestCase;

class ProviderFeatureProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN', 'fixture-csrf');
        config(['prism.providers.anthropic.api_key' => 'fixture-only', 'prism.telemetry.enabled' => false]);
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'public.test' => ['93.184.216.34'],
                    'mixed.test' => ['93.184.216.34', '127.0.0.1'],
                    default => [],
                };
            }
        });
    }

    public static function refusedUrls(): array
    {
        return [
            ['http://169.254.169.254/latest/meta-data', 'private_address_refused'],
            ['http://127.0.0.1/', 'private_address_refused'],
            ['http://mixed.test/', 'private_address_refused'],
            ['file:///etc/passwd', 'scheme_not_allowed'],
            ['https://unresolved.test/', 'host_did_not_resolve'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_refusal_code_reaches_surface_without_a_request(string $url, string $code): void
    {
        $this->postJson('/lab/provider-probes/fetch', ['url' => $url])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('code', $code);
        Http::assertNothingSent();
    }

    public function test_redirect_to_private_address_is_refused_before_second_request(): void
    {
        Http::fake(['https://public.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $this->postJson('/lab/provider-probes/fetch', ['url' => 'https://public.test/start'])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('code', 'redirect_refused');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://public.test/start');
    }

    public function test_public_fetch_positive_control_returns_observed_content(): void
    {
        Http::fake(['https://public.test/*' => Http::response('Public fixture content')]);
        $this->postJson('/lab/provider-probes/fetch', ['url' => 'https://public.test/file'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('bytes', 22)
            ->assertJsonPath('preview', 'Public fixture content');
        Http::assertSentCount(1);
    }

    public function test_cache_surface_sends_stable_prefix_and_volatile_question_and_shows_token_evidence(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicBody(2048, 0))
            ->push($this->anthropicBody(0, 2048))]);
        $this->postJson('/lab/provider-probes/cache', [
            'model' => 'claude-sonnet-5', 'prefix' => 'Stable reference', 'question' => 'First question', 'follow_up' => 'Second question',
        ])->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('runs.0.usage.cache_write_input_tokens', 2048)
            ->assertJsonPath('runs.0.usage.cache_read_input_tokens', 0)
            ->assertJsonPath('runs.1.usage.cache_write_input_tokens', 0)
            ->assertJsonPath('runs.1.usage.cache_read_input_tokens', 2048);
        Http::assertSentCount(2);
        foreach (Http::recorded() as $index => [$request]) {
            $messages = $request['messages'];
            $this->assertSame('Stable reference', $messages[0]['content'][0]['text']);
            $this->assertSame(['type' => 'ephemeral'], $messages[0]['content'][0]['cache_control']);
            $this->assertSame($index === 0 ? 'First question' : 'Second question', $messages[1]['content'][0]['text']);
            $this->assertArrayNotHasKey('cache_control', $messages[1]['content'][0]);
            $this->assertSame(128, $request['max_tokens']);
        }
    }

    public function test_zero_cache_counts_are_not_claimed_as_a_hit(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicBody(0, 0))]);
        $this->postJson('/lab/provider-probes/cache', ['model' => 'claude-sonnet-5', 'prefix' => 'Short prefix', 'question' => 'One', 'follow_up' => 'Two'])
            ->assertOk()->assertJsonPath('runs.1.usage.cache_read_input_tokens', 0);
    }

    public function test_second_call_failure_keeps_first_call_usage_evidence(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->anthropicBody(2048, 0))
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Fixture failure']], 400)]);
        $this->postJson('/lab/provider-probes/cache', ['model' => 'claude-sonnet-5', 'prefix' => 'Reference', 'question' => 'One', 'follow_up' => 'Two'])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonCount(1, 'runs')->assertJsonPath('runs.0.usage.cache_write_input_tokens', 2048);
    }

    public function test_probe_inputs_and_local_boundary_prevent_requests(): void
    {
        $this->postJson('/lab/provider-probes/cache', ['model' => 'model'])->assertUnprocessable();
        $this->postJson('/lab/provider-probes/fetch', ['url' => str_repeat('x', 2049)])->assertUnprocessable();
        foreach (['fetch', 'cache'] as $probe) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->postJson('/lab/provider-probes/'.$probe, [])->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_probe_keeps_csrf_protection(): void
    {
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/lab/provider-probes/fetch', ['url' => 'https://public.test/file'])->assertStatus(419);
        Http::assertNothingSent();
    }

    private function anthropicBody(int $write, int $read): array
    {
        return ['id' => 'msg_fixture', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => 'Fixture answer']], 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read]];
    }
}
