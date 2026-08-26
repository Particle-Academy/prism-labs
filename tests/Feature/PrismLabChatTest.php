<?php

namespace Tests\Feature;

use App\Http\Controllers\Lab\ChatController;
use App\Http\Controllers\Lab\TestSuiteController;
use App\Http\Middleware\EnsurePrismLabIsLocal;
use App\Lab\BenchmarkStore;
use App\Lab\PrismTestRegistry;
use App\Lab\PrismTestRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Tests\TestCase;

class PrismLabChatTest extends TestCase
{
    public function test_it_keeps_prism_lab_routes_out_of_non_local_environments(): void
    {
        $this->expectException(RouteNotFoundException::class);

        route('lab.chat', absolute: false);
    }

    public function test_it_returns_an_actionable_response_when_a_provider_key_is_missing(): void
    {
        config()->set('prism.providers.openai.api_key', '');

        $request = Request::create('/lab/chat', 'POST', [
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'feature' => 'text',
            'prompt' => 'Hello',
        ]);

        $response = app(ChatController::class)->run($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'message' => 'Set OPENAI_API_KEY in repos/prism-sandbox/.env, then reload.',
            'missing_key' => true,
        ], $response->getData(true));
    }

    public function test_local_lab_middleware_rejects_non_loopback_requests(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        $request = Request::create('/lab/chat', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);

        $this->expectException(NotFoundHttpException::class);

        app(EnsurePrismLabIsLocal::class)->handle($request, fn () => response('ok'));
    }

    public function test_test_suite_rejects_duplicate_cases(): void
    {
        $request = Request::create('/lab/tests', 'POST', [
            'cases' => ['openai.text', 'openai.text'],
        ]);

        $this->expectException(ValidationException::class);

        app(TestSuiteController::class)->run(
            $request,
            app(PrismTestRegistry::class),
            app(PrismTestRunner::class),
            app(BenchmarkStore::class),
        );
    }
}
