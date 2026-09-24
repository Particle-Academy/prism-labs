<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN', 'fixture-csrf');
        config(['prism.providers.perplexity.api_key' => 'fixture-only', 'team.research.model' => 'sonar', 'team.research.max_tokens' => 64, 'prism.telemetry.enabled' => false]);
        Storage::fake('local');
        Http::preventStrayRequests();
        Log::spy();
        Exceptions::fake();
    }

    public static function failures(): array
    {
        return [['incomplete'], ['failed'], ['cancelled']];
    }

    #[DataProvider('failures')]
    public function test_diagnostics_reach_research_and_provider_matrix_without_entering_logs(string $status): void
    {
        $body = $this->body($status);
        Http::fake(['api.perplexity.ai/*' => Http::response($body)]);
        $expected = [
            'code' => 'run_'.$status,
            'run_id' => 'run_fixture',
            'incomplete_reason' => 'max_output_tokens',
            'output' => $body['output'],
            'citations' => [['type' => 'url_citation', 'url' => 'https://example.test/source', 'title' => 'Source']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 64, 'cache_write_input_tokens' => null, 'cache_read_input_tokens' => null, 'thought_tokens' => null, 'cost' => 0.01],
        ];

        $this->postJson('/lab/provider-probes/research', ['question' => 'Fixture research'])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('diagnostics', $expected);
        $this->postJson('/lab/tests', ['cases' => ['perplexity.text']])
            ->assertOk()->assertJsonPath('results.0.passed', false)->assertJsonPath('results.0.diagnostics', $expected);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.perplexity.ai/*' => Http::response('data: '.json_encode(['type' => 'response.'.$status, 'response' => $body])."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $this->postJson('/lab/tests', ['cases' => ['perplexity.streaming']])
            ->assertOk()->assertJsonPath('results.0.passed', false)->assertJsonPath('results.0.diagnostics', $expected);

        Log::shouldNotHaveReceived('error');
        Exceptions::assertNothingReported();
        $history = Storage::disk('local')->get('lab/benchmarks.jsonl');
        $this->assertStringNotContainsString('Private partial finding', $history);
        $this->assertStringNotContainsString('https://example.test/source', $history);
        $this->assertStringNotContainsString('run_fixture', $history);
    }

    public function test_completed_control_returns_answer_without_failure_diagnostics(): void
    {
        Http::fake(['api.perplexity.ai/*' => Http::response($this->body('completed'))]);
        $this->postJson('/lab/provider-probes/research', ['question' => 'Fixture research'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('answer', 'Private partial finding')->assertJsonMissingPath('diagnostics');
        $this->postJson('/lab/tests', ['cases' => ['perplexity.text']])
            ->assertOk()->assertJsonPath('results.0.passed', true)->assertJsonPath('results.0.diagnostics', null);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.perplexity.ai/*' => Http::response('data: '.json_encode(['type' => 'response.completed', 'response' => $this->body('completed')])."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $this->postJson('/lab/tests', ['cases' => ['perplexity.streaming']])
            ->assertOk()->assertJsonPath('results.0.passed', true)->assertJsonPath('results.0.diagnostics', null);
    }

    public function test_surface_is_local_only_and_page_load_makes_no_provider_calls(): void
    {
        $this->get('/lab/provider-probes')->assertOk();
        Http::assertNothingSent();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->postJson('/lab/provider-probes/research', ['question' => 'Denied'])->assertNotFound();
        $this->app->detectEnvironment(fn () => 'production');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/lab/provider-probes/research', ['question' => 'Denied'])->assertNotFound();
        Http::assertNothingSent();
    }

    private function body(string $status): array
    {
        return [
            'id' => 'run_fixture', 'status' => $status, 'model' => 'sonar',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Private partial finding', 'annotations' => [['type' => 'url_citation', 'url' => 'https://example.test/source', 'title' => 'Source']]]]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 64, 'cost' => ['total_cost' => 0.01]],
        ];
    }
}
