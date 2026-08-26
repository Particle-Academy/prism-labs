<?php

namespace Tests\Feature;

use App\Http\Controllers\Lab\ChatController;
use App\Lab\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LabProviderRegistryTest extends TestCase
{
    public function test_it_lists_every_provider_prism_ships(): void
    {
        $registry = app(ProviderRegistry::class);

        $keys = array_column($registry->all(), 'key');

        // The list must come from Prism's config, not a copy in the Lab.
        $this->assertSame(array_keys(config('prism.providers')), $keys);
    }

    public function test_every_shipped_provider_has_a_lab_descriptor(): void
    {
        // Guards the drift this registry exists to prevent: a provider added to
        // Prism shows up with a guessed label and no setup hint until described.
        $this->assertSame([], app(ProviderRegistry::class)->undescribed());
    }

    public function test_it_reports_configured_state_from_the_key(): void
    {
        config()->set('prism.providers.groq.api_key', '');
        $this->assertFalse(app(ProviderRegistry::class)->isConfigured('groq'));

        config()->set('prism.providers.groq.api_key', 'gsk_test');
        $this->assertTrue(app(ProviderRegistry::class)->isConfigured('groq'));
    }

    public function test_keyless_providers_are_configured_by_url(): void
    {
        // Ollama has no api_key slot — it is reachable when a URL is set.
        $registry = app(ProviderRegistry::class);

        $ollama = $registry->find('ollama');

        $this->assertNotNull($ollama);
        $this->assertFalse($ollama['requiresKey']);
        $this->assertTrue($ollama['configured']);
    }

    public function test_non_text_providers_are_excluded_from_the_chat_picker(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertNotContains('elevenlabs', $registry->textProviderKeys());
        $this->assertNotContains('voyageai', $registry->textProviderKeys());
        $this->assertContains('openai', $registry->textProviderKeys());
    }

    public function test_setup_hints_name_the_env_var(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertStringContainsString('GEMINI_API_KEY', $registry->setupHint('gemini'));
        $this->assertStringContainsString('Ollama', $registry->setupHint('ollama'));
        $this->assertStringContainsString('Unknown provider', $registry->setupHint('nope'));
    }

    public function test_any_text_provider_reaches_the_setup_hint_rather_than_a_validation_error(): void
    {
        // Lab routes only exist in local, so drive the controller directly —
        // same approach as PrismLabChatTest.
        config()->set('prism.providers.gemini.api_key', '');

        $response = app(ChatController::class)->run(Request::create('/lab/chat', 'POST', [
            'provider' => 'gemini',
            'model' => 'gemini-flash-latest',
            'feature' => 'text',
            'prompt' => 'hello',
        ]));

        // Before the registry, only openai|anthropic passed validation, so any
        // other provider died with a rule violation that explained nothing.
        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['missing_key']);
        $this->assertStringContainsString('GEMINI_API_KEY', $response->getData(true)['message']);
    }

    public function test_a_non_text_provider_is_rejected_by_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ChatController::class)->run(Request::create('/lab/chat', 'POST', [
            'provider' => 'elevenlabs',
            'model' => 'whatever',
            'feature' => 'text',
            'prompt' => 'hello',
        ]));
    }
}
