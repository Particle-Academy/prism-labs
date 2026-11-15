<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\PolicyAdherenceAgent;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PerplexityResearchProbe;
use App\Lab\PrismTestCase;
use App\Lab\PrismTestRegistry;
use App\Lab\PrismTestRunner;
use App\Lab\ProviderFeatureProbe;
use App\Lab\RunAuthority;
use App\Research\Researcher;
use App\Tasks\TaskAgentProbe;
use App\Team\Coordinator;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

final class DirectPaidServiceAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    #[DataProvider('directServices')]
    public function test_direct_paid_services_refuse_without_authority_before_inference(string $service): void
    {
        Http::preventStrayRequests();
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned fake output')]);
        $this->refuses(fn () => $this->invoke($service));
        $fake->assertCallCount(0);
        Http::assertNothingSent();
    }

    public static function directServices(): array
    {
        return array_map(fn ($name) => [$name], ['coordinator', 'research', 'search', 'tasks', 'policy', 'cache', 'test', 'deep-research']);
    }

    #[DataProvider('directServices')]
    public function test_other_approved_actions_do_not_authorize_a_direct_service(string $service): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('provider.cache', [], fn () => $this->refuses(fn () => $this->invoke($service)));
        $fake->assertCallCount(0);
    }

    public function test_approved_coordinator_calls_preserve_the_question_and_native_result(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned answer')]);
        $result = $this->withApprovedLabRun('team.ask', ['question' => 'Owned question'], fn () => $this->invoke('coordinator'));
        $this->assertSame('Owned answer', $result['text']);
        $fake->assertCallCount(1);
        $fake->assertPrompt('Owned question');
    }

    public function test_coordinator_cannot_substitute_a_different_question(): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('team.ask', ['question' => 'Reviewed question'], fn () => $this->refuses(fn () => $this->invoke('coordinator')));
        $fake->assertCallCount(0);
    }

    public function test_direct_research_requires_its_exact_question_and_preserves_the_response(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned cited answer')]);
        $this->withApprovedLabRun('provider.research', ['question' => 'Owned question'], function (): void {
            $this->refuses(fn () => app(Researcher::class)->ask('Substituted question'));
            $this->assertSame('Owned cited answer', $this->invoke('research')['answer']);
            $this->refuses(fn () => app(Researcher::class)->search('Owned question'));
        });
        $fake->assertCallCount(1);
    }

    public function test_nested_research_and_search_keep_generated_questions_with_the_configured_bound(): void
    {
        config(['team.research.max_results' => 3, 'prism.providers.perplexity.api_key' => 'owned-search-fixture']);
        // Keep the actual extended search provider while Prism's text facade
        // records model requests. Its global fake deliberately has no search().
        $searcher = app(Researcher::class);
        $fake = Prism::fake([TextResponseFake::make()->withText('Nested answer')]);
        $this->withApprovedLabRun('team.ask', ['question' => 'Initial reviewed question'], function () use ($searcher): void {
            Http::fake(['https://api.perplexity.ai/search' => Http::response(['results' => [
                ['title' => 'Owned source', 'url' => 'https://example.test/source'],
            ]])]);
            $this->assertSame('Nested answer', app(Researcher::class)->ask('Generated follow-up')['answer']);
            $result = $searcher->search('Generated source query', 3);
            $this->assertTrue($result['ok']);
            $this->assertSame('Owned source', $result['results'][0]['title']);
            $this->refuses(fn () => app(Researcher::class)->search('Generated source query', 4));
            $this->refuses(fn () => app(Researcher::class)->search('Generated source query', 0));
            Http::assertSent(fn ($request) => $request->url() === 'https://api.perplexity.ai/search'
                && $request['query'] === 'Generated source query' && $request['max_results'] === 3);
        });
        $fake->assertCallCount(1);
    }

    public function test_research_rechecks_revocation_before_a_later_tool_call(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('First answer')]);
        [$intent, $scope] = $this->approvedLabRun('team.ask', ['question' => 'Owned question']);
        app(RunAuthority::class)->consume($intent->id, $scope);
        app(PaidRunContext::class)->within($intent->id, $scope, function () use ($intent): void {
            $this->assertSame('First answer', app(Researcher::class)->ask('Generated question')['answer']);
            $intent->forceFill(['revoked_at' => now()])->save();
            $this->refuses(fn () => app(Researcher::class)->ask('Another generated question'));
        });
        $this->refuses(fn () => $this->invoke('research'));
        $fake->assertCallCount(1);
    }

    public function test_runtime_option_drift_refuses_before_coordinator_builder(): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('team.ask', ['question' => 'Owned question'], function (): void {
            config(['team.coordinator.max_steps' => 99]);
            $this->refuses(fn () => $this->invoke('coordinator'));
        });
        $fake->assertCallCount(0);
    }

    #[DataProvider('effectiveOptions')]
    public function test_effective_harness_and_transport_options_are_frozen(string $key, mixed $value): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('team.ask', ['question' => 'Owned question'], function () use ($key, $value): void {
            config([$key => $value]);
            $this->refuses(fn () => $this->invoke('coordinator'));
        });
        $fake->assertCallCount(0);
    }

    public static function effectiveOptions(): array
    {
        return [['prism-harness.agent.modes.chat.max_steps', 99], ['prism-harness.agent.authorize_tools', true],
            ['prism-harness.context.keep_recent', null], ['team.endpoints.ts', 'http://127.0.0.1:7999/mcp'],
            ['prism.request_timeout', 999], ['prism-harness.agent.modes.scoring.tools', ['*']],
            ['prism-harness.agent.model', 'unreviewed-model']];
    }

    public function test_versioned_coordinator_prompt_bytes_are_frozen(): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('team.ask', ['question' => 'Owned question'], function (): void {
            $path = resource_path('prompts/coordinator.md');
            $original = file_get_contents($path);
            try {
                file_put_contents($path, $original."\nOwned changed instruction.\n");
                $this->refuses(fn () => $this->invoke('coordinator'));
            } finally {
                file_put_contents($path, $original);
                $this->assertSame($original, file_get_contents($path));
            }
        });
        $fake->assertCallCount(0);
    }

    public function test_builder_exception_stays_native_and_does_not_leave_paid_context_behind(): void
    {
        $failure = new \RuntimeException('Owned native builder failure');
        Prism::shouldReceive('text')->once()->andThrow($failure);
        try {
            $this->withApprovedLabRun('team.ask', ['question' => 'Owned question'], fn () => $this->invoke('coordinator'));
            $this->fail('The native builder exception was swallowed.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($failure, $observed);
        }
        $this->refuses(fn () => $this->invoke('coordinator'));
    }

    public function test_an_expired_run_cannot_authorize_direct_research(): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('provider.research', ['question' => 'Owned question'], function (): void {
            $this->travel(16)->minutes();
            try {
                $this->refuses(fn () => $this->invoke('research'));
            } finally {
                $this->travelBack();
            }
        });
        $fake->assertCallCount(0);
    }

    public function test_programmatic_ask_cli_consumes_only_the_preexisting_owner_go_once(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned CLI answer')]);
        [$intent] = $this->approvedLabRun('team.ask', ['question' => 'Owned question']);
        Auth::guard('web')->logout();
        $arguments = ['question' => 'Owned question', '--approved-run' => $intent->id];
        $this->artisan('team:ask', $arguments)->expectsOutputToContain('Owned CLI answer')->assertSuccessful();
        $this->artisan('team:ask', $arguments)->expectsOutputToContain('paid_call_not_authorised')->assertFailed();
        $this->assertGuest('web');
        $this->refuses(fn () => $this->invoke('coordinator'));
        $fake->assertCallCount(1);
    }

    public function test_approved_task_probe_runs_the_native_harness_and_cleans_its_task_list(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned changelog sentence')]);
        $result = $this->withApprovedLabRun('tasks.agent', [], fn () => $this->invoke('tasks'));
        $this->assertTrue($result['ran']);
        $this->assertSame('inconclusive', $result['verdict']);
        $this->assertSame('Owned changelog sentence', $result['text']);
        $this->assertSame('done', $result['closed_by_application']);
        $fake->assertCallCount(1);
    }

    public function test_approved_policy_turn_preserves_messages_and_optional_context_management(): void
    {
        $input = $this->policyInput();
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned policy answer')]);
        $result = $this->withApprovedLabRun('benchmarks.policy-turn', $input, fn () => $this->policyTurn($input));
        $this->assertSame('Owned policy answer', $result['content']);
        $fake->assertCallCount(1);
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('owned-model', $requests[0]->model());
            $this->assertSame('Owned question', $requests[0]->messages()[0]->content);
        });
    }

    #[DataProvider('policyChanges')]
    public function test_policy_turn_binds_every_actual_argument(string $key, mixed $value): void
    {
        $input = $this->policyInput();
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('benchmarks.policy-turn', $input,
            fn () => $this->refuses(fn () => $this->policyTurn(array_replace($input, [$key => $value]))));
        $fake->assertCallCount(0);
    }

    public static function policyChanges(): array
    {
        return [['provider', 'openai'], ['model', 'other-model'], ['policy', 'Other policy'],
            ['tools', [['name' => 'other-tool']]], ['messages', [['role' => 'user', 'content' => 'Other prompt']]],
            ['context_management', ['edits' => [['type' => 'clear_tool_uses_20250919']]]]];
    }

    public function test_approved_cache_probe_keeps_both_native_requests(): void
    {
        $input = $this->cacheInput();
        $fake = Prism::fake([TextResponseFake::make()->withText('First answer'), TextResponseFake::make()->withText('Second answer')]);
        $result = $this->withApprovedLabRun('provider.cache', $input, fn () => $this->invoke('cache'));
        $this->assertTrue($result['ok']);
        $this->assertSame(['First answer', 'Second answer'], array_column($result['runs'], 'answer'));
        $fake->assertCallCount(2);
    }

    #[DataProvider('cacheChanges')]
    public function test_cache_probe_binds_each_input(string $key): void
    {
        $fake = Prism::fake([]);
        $input = $this->cacheInput();
        $this->withApprovedLabRun('provider.cache', $input, function () use ($input, $key): void {
            $changed = array_replace($input, [$key => 'Other reviewed value']);
            $this->refuses(fn () => app(ProviderFeatureProbe::class)->cache($changed['model'], $changed['prefix'], $changed['question'], $changed['follow_up']));
        });
        $fake->assertCallCount(0);
    }

    public static function cacheChanges(): array
    {
        return [['model'], ['prefix'], ['question'], ['follow_up']];
    }

    public function test_only_a_selected_unchanged_registry_case_can_run(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned text result')]);
        $this->withApprovedLabRun('tests.run', ['cases' => ['anthropic.text']], function (): void {
            $this->refuses(fn () => app(PrismTestRunner::class)->run(app(PrismTestRegistry::class)->find('openai.text')));
            $this->refuses(fn () => app(PrismTestRunner::class)->run(new PrismTestCase('anthropic.text', 'anthropic', 'other-model', 'text', 'Anthropic text')));
            $this->assertTrue($this->invoke('test')['passed']);
        });
        $fake->assertCallCount(1);
    }

    public function test_cli_costly_selection_requires_the_actual_costly_flag(): void
    {
        $fake = Prism::fake([]);
        $this->withApprovedLabRun('cli.tests.run', ['cases' => ['perplexity.research.completed'], 'include_costly' => false],
            fn () => $this->refuses(fn () => $this->invoke('deep-research')));
        $fake->assertCallCount(0);
    }

    public function test_selected_deep_research_uses_its_existing_native_path(): void
    {
        config(['prism.providers.perplexity.api_key' => 'owned-search-fixture']);
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned deep result')]);
        $result = $this->withApprovedLabRun('tests.run', ['cases' => ['perplexity.research.completed']], fn () => $this->invoke('deep-research'));
        $this->assertArrayHasKey('metrics', $result);
        $this->assertSame('perplexity.research.completed', $result['id']);
        $fake->assertCallCount(1);
    }

    private function policyInput(): array
    {
        return ['provider' => 'anthropic', 'model' => 'owned-model', 'policy' => 'Owned policy',
            'tools' => [], 'messages' => [['role' => 'user', 'content' => 'Owned question']]];
    }

    private function policyTurn(array $input): array
    {
        return app(PolicyAdherenceAgent::class)->turn($input['provider'], $input['model'], $input['policy'],
            $input['tools'], $input['messages'], $input['context_management'] ?? null);
    }

    private function cacheInput(): array
    {
        return ['model' => 'owned-model', 'prefix' => 'Owned prefix', 'question' => 'Owned question', 'follow_up' => 'Owned follow-up'];
    }

    private function invoke(string $service): mixed
    {
        return match ($service) {
            'coordinator' => app(Coordinator::class)->ask('Owned question'),
            'research' => app(Researcher::class)->ask('Owned question'),
            'search' => app(Researcher::class)->search('Owned question'),
            'tasks' => app(TaskAgentProbe::class)->run(),
            'policy' => app(PolicyAdherenceAgent::class)->turn('anthropic', 'owned-model', 'Owned policy', [], []),
            'cache' => app(ProviderFeatureProbe::class)->cache('owned-model', 'Owned prefix', 'Owned question', 'Owned follow-up'),
            'test' => app(PrismTestRunner::class)->run(app(PrismTestRegistry::class)->find('anthropic.text')),
            'deep-research' => app(PerplexityResearchProbe::class)->run(app(PrismTestRegistry::class)->find('perplexity.research.completed')),
        };
    }

    private function refuses(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('A direct paid service ran without matching owner authority.');
        } catch (PaidRunNotAuthorised $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertSame((new PaidRunNotAuthorised)->getMessage(), $error->getMessage());
        }
    }
}
