<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Acp\ApprovedDriver;
use App\Acp\ApprovedPrompt;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Lab\RunScope;
use App\Lab\RunScopeFactory;
use App\Lab\SessionNotReady;
use App\Lab\WorkerHeartbeats;
use App\Models\AcpTurn;
use App\Models\LabAuthoritySession;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Acp\Agent\AgentDriver;
use Prism\Acp\Agent\CancelsTurns;
use Prism\Acp\Agent\OwnsProcessTree;
use Prism\Acp\Agent\ReportsModels;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Process\ProcessTree;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class RunAuthorityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/authority-'.bin2hex(random_bytes(8)));
        config([
            'lab-preflight.workers.directory' => $this->directory.'/workers',
            'cache.stores.file.path' => $this->directory.'/cache',
            'cache.stores.file.lock_path' => $this->directory.'/cache',
            'queue.default' => 'database', 'queue.connections.database.retry_after' => 90,
            'team.coordinator.provider' => 'anthropic', 'prism.providers.anthropic.api_key' => 'synthetic-fixture-only',
        ]);
        Cache::forgetDriver('file');
        $this->app->forgetInstance(WorkerHeartbeats::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->owner->id]);
        $this->actingAs($this->owner, 'web');
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        Http::fake(function (Request $request) {
            $this->assertStringStartsWith('https://api.github.com/repos/Particle-Academy/prism/', $request->url(), 'Only fixture core metadata, never a provider request.');
            $head = str_repeat('a', 40);
            $runs = array_map(fn ($path, $index) => ['id' => 100 + $index, 'run_number' => 1, 'path' => $path,
                'head_sha' => $head, 'head_branch' => 'main', 'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'],
                config('lab-preflight.core_actions.workflows'), array_keys(config('lab-preflight.core_actions.workflows')));

            return Http::response(str_contains($request->url(), '/git/ref/')
                ? ['ref' => 'refs/heads/main', 'object' => ['sha' => $head, 'type' => 'commit']]
                : ['total_count' => count($runs), 'workflow_runs' => $runs]);
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        if (isset($this->directory)) {
            app(Filesystem::class)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_login_and_drafting_do_not_approve_or_launch_work(): void
    {
        $intent = $this->authority()->draft($this->scope(), '1.25');
        $this->assertSame('draft', $intent->status);
        $this->assertSame(1_250_000, $intent->cap_microusd);
        $this->assertNull($intent->approved_by);
        $this->assertNull($intent->go_at);
        $this->assertSame(0, LabAuthoritySession::query()->sole()->allocated_microusd);
        $this->refuses(fn () => $this->authority()->consume($intent->id, $this->scope()));
        Http::assertNothingSent();
    }

    public function test_only_an_actual_owner_approval_and_exact_go_allow_one_launch(): void
    {
        $intent = $this->ready('1.25');
        $this->assertSame($this->owner->id, $intent->approved_by);
        $this->assertSame($this->owner->id, $intent->go_by);
        $this->assertSame($intent->digest, $intent->approved_digest);
        $this->assertSame($intent->digest, $intent->go_digest);
        $consumed = $this->authority()->consume($intent->id, $this->scope());
        $this->assertSame('running', $consumed->status);
        $this->assertNotNull($consumed->consumed_at);
        $this->assertSame(1_250_000, LabAuthoritySession::query()->sole()->allocated_microusd);
        $this->refuses(fn () => $this->authority()->consume($intent->id, $this->scope()));
        Http::assertSentCount(2);
    }

    public function test_approval_alone_is_not_go_and_a_cli_cannot_self_approve(): void
    {
        $intent = $this->authority()->draft($this->scope(), '1');
        $this->authority()->approve($intent->id, $intent->digest);
        $this->refuses(fn () => $this->authority()->consume($intent->id, $this->scope()));
        Auth::guard('web')->logout();
        try {
            $this->authority()->approve($intent->id, $intent->digest);
            $this->fail('An unauthenticated CLI approved a run.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_a_cli_can_consume_an_existing_exact_go_without_impersonating_an_owner(): void
    {
        $intent = $this->ready();
        Auth::guard('web')->logout();
        $this->assertGuest('web');
        $this->assertSame('running', $this->authority()->consume($intent->id, $this->scope())->status);
        $this->assertGuest('web');
    }

    #[DataProvider('wrongScopes')]
    public function test_an_authority_cannot_be_reused_for_other_work(string $action, string $resource, array $payload): void
    {
        $intent = $this->ready();
        $this->refuses(fn () => $this->authority()->consume($intent->id, new RunScope($action, $resource, $payload)));
        $this->assertSame('ready', $intent->fresh()->status);
        Http::assertNothingSent();
    }

    public static function wrongScopes(): array
    {
        return [
            ['tests.run', 'lab:agent', ['message' => 'owned question']],
            ['team.ask', 'lab:other', ['message' => 'owned question']],
            ['team.ask', 'lab:agent', ['message' => 'changed question']],
            ['team.ask', 'lab:agent', ['message' => 'owned question', 'approved' => true]],
        ];
    }

    public function test_an_owner_cannot_approve_a_digest_they_did_not_review(): void
    {
        $intent = $this->authority()->draft($this->scope(), '1');
        $this->refuses(fn () => $this->authority()->approve($intent->id, str_repeat('b', 64)));
        $this->assertSame('draft', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->approved_at);
    }

    #[DataProvider('tamperedIntentFields')]
    public function test_changed_approved_work_refuses_before_preflight(string $field, mixed $value): void
    {
        $intent = $this->ready();
        $intent->forceFill([$field => $value])->save();
        $this->refuses(fn () => $this->authority()->consume($intent->id, $this->scope()));
        Http::assertNothingSent();
    }

    public static function tamperedIntentFields(): array
    {
        return [['payload', ['message' => 'changed']], ['action', 'tests.run'], ['resource', 'lab:other'],
            ['cap_microusd', 5_000_000], ['digest', str_repeat('b', 64)],
            ['approved_digest', str_repeat('b', 64)], ['go_digest', str_repeat('b', 64)],
            ['approved_by', null], ['go_at', null], ['consumed_at', '2026-01-01 00:00:00']];
    }

    public function test_go_requires_the_exact_cap_digest_and_literal_confirmation(): void
    {
        $intent = $this->authority()->draft($this->scope(), '1');
        $this->authority()->approve($intent->id, $intent->digest);
        $this->refuses(fn () => $this->authority()->go($intent->id, $intent->digest, '2', 'GO'));
        $this->refuses(fn () => $this->authority()->go($intent->id, str_repeat('b', 64), '1', 'GO'));
        $this->refuses(fn () => $this->authority()->go($intent->id, $intent->digest, '1', 'go'));
        $this->assertSame('approved', $intent->fresh()->status);
        $this->assertSame(0, LabAuthoritySession::query()->sole()->allocated_microusd);
    }

    public function test_the_five_dollar_logical_session_cap_survives_cookie_rotation(): void
    {
        $first = $this->ready('3');
        session()->regenerate();
        Auth::guard('web')->logout();
        $this->actingAs($this->owner, 'web');
        $second = $this->authority()->draft($this->scope(), '3');
        $this->assertSame($first->authority_session_id, $second->authority_session_id);
        $this->authority()->approve($second->id, $second->digest);
        $this->refuses(fn () => $this->authority()->go($second->id, $second->digest, '3', 'GO'));
        $this->assertSame(3_000_000, LabAuthoritySession::query()->sole()->allocated_microusd);
        $this->assertSame('approved', $second->fresh()->status);
        $this->refuses(fn () => $this->authority()->go($first->id, $first->digest, '3', 'GO'));
        $this->assertSame(1, LabAuthoritySession::query()->count());
        Http::assertNothingSent();
    }

    public function test_revocation_releases_only_unlaunched_allocation_and_never_enables_replay(): void
    {
        $first = $this->ready('2');
        $this->authority()->revoke($first->id);
        $this->authority()->revoke($first->id);
        $this->assertSame(0, LabAuthoritySession::query()->sole()->allocated_microusd);
        $this->refuses(fn () => $this->authority()->consume($first->id, $this->scope()));
        $second = $this->ready('5');
        $this->authority()->consume($second->id, $this->scope());
        Event::dispatch(new WorkerStopping);
        $this->authority()->revoke($second->id);
        $this->assertSame(5_000_000, LabAuthoritySession::query()->sole()->allocated_microusd);
        $this->refuses(fn () => $this->authority()->assertActive($second->id, $this->scope()));
    }

    public function test_an_expired_intent_cannot_launch_or_refresh_its_own_expiry(): void
    {
        $intent = $this->ready();
        $expires = $intent->expires_at->getTimestamp();
        $this->travel(16)->minutes();
        $this->refuses(fn () => $this->authority()->consume($intent->id, $this->scope()));
        $this->assertSame($expires, $intent->fresh()->expires_at->getTimestamp());
        Http::assertNothingSent();
    }

    public function test_owner_configuration_changes_revoke_the_old_owners_authority(): void
    {
        $intent = $this->ready();
        config(['lab_authority.owner_user_id' => User::factory()->create()->id]);
        $this->refuses(fn () => $this->authority()->consume($intent->id, $this->scope()));
        Http::assertNothingSent();
    }

    public function test_current_preflight_is_required_and_a_failure_does_not_consume_the_launch(): void
    {
        $intent = $this->ready();
        Event::dispatch(new WorkerStopping);
        try {
            $this->authority()->consume($intent->id, $this->scope());
            $this->fail('A run launched without current worker capacity.');
        } catch (SessionNotReady) {
            $this->assertSame('ready', $intent->fresh()->status);
            $this->assertNull($intent->fresh()->consumed_at);
        }
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        $this->assertSame('running', $this->authority()->consume($intent->id, $this->scope())->status);
    }

    public function test_delayed_work_must_recheck_active_scope_and_revocation(): void
    {
        $intent = $this->ready();
        $this->authority()->consume($intent->id, $this->scope());
        $this->assertSame($intent->id, $this->authority()->assertActive($intent->id, $this->scope())->id);
        $this->authority()->revoke($intent->id);
        $this->refuses(fn () => $this->authority()->assertActive($intent->id, $this->scope()));
    }

    #[DataProvider('invalidCaps')]
    public function test_caps_use_exact_bounded_decimal_arithmetic(string $value): void
    {
        $this->expectException(ValidationException::class);
        $this->authority()->draft($this->scope(), $value);
    }

    public static function invalidCaps(): array
    {
        return array_map(fn ($value) => [$value], ['0', '6', '5.000001', '+1', '01', '1e0', ' 1', '1 ',
            'NaN', 'INF', '-1', '0.000000', '0.0000001', '1.']);
    }

    public function test_a_microdollar_is_preserved_and_map_key_order_does_not_change_scope(): void
    {
        $scope = new RunScope('team.ask', 'lab:agent', ['a' => 1, 'b' => ['first', 'second']]);
        $intent = $this->authority()->draft($scope, '0.000001');
        $this->authority()->approve($intent->id, $intent->digest);
        $this->authority()->go($intent->id, $intent->digest, '0.000001', 'GO');
        $this->assertSame(1, $this->authority()->consume($intent->id, new RunScope('team.ask', 'lab:agent', ['b' => ['first', 'second'], 'a' => 1]))->cap_microusd);
    }

    public function test_missing_and_malformed_references_cannot_create_approval_records(): void
    {
        foreach (['', 'Wish', '00000000-0000-4000-8000-000000000001'] as $id) {
            $this->refuses(fn () => $this->authority()->consume($id, $this->scope()));
        }
        $this->assertSame(0, LabRunIntent::query()->count());
        Http::assertNothingSent();
    }

    public function test_paid_context_is_removed_even_when_native_work_throws(): void
    {
        [$intent, $scope] = $this->activatedContextScope();
        $context = app(PaidRunContext::class);
        try {
            $context->within($intent->id, $scope, function () use ($context, $intent): void {
                $this->assertSame($intent->id, $context->requireActions(['team.ask'])->id);
                throw new \RuntimeException('synthetic-work-failure');
            });
            $this->fail('The native work exception was swallowed.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('synthetic-work-failure', $failure->getMessage());
        }
        $this->refuses(fn () => $context->requireActions(['team.ask']));
    }

    public function test_paid_context_cannot_promote_a_label_or_nested_forged_reference(): void
    {
        [$intent, $scope] = $this->activatedContextScope();
        $context = app(PaidRunContext::class);
        $context->within($intent->id, $scope, function () use ($context, $intent, $scope): void {
            $this->refuses(fn () => $context->requireActions(['tests.run']));
            $this->refuses(fn () => $context->within('00000000-0000-4000-8000-000000000001', $scope,
                fn () => $context->requireActions(['team.ask'])));
            $this->assertSame($intent->id, $context->requireActions(['team.ask'])->id);
        });
        $this->refuses(fn () => $context->requireActions(['team.ask']));
    }

    public function test_paid_context_recomputes_runtime_instead_of_trusting_a_stored_green_scope(): void
    {
        [$intent, $scope] = $this->activatedContextScope();
        $context = app(PaidRunContext::class);
        config(['team.coordinator.model' => 'changed-after-go']);
        $context->within($intent->id, $scope, fn () => $this->refuses(fn () => $context->requireActions(['team.ask'])));
    }

    /** @return array{LabRunIntent, RunScope} */
    private function activatedContextScope(): array
    {
        $scope = app(RunScopeFactory::class)->fromAction('team.ask', 'lab:agent', ['question' => 'Owned context fixture']);
        $intent = $this->authority()->draft($scope, '1');
        $this->authority()->approve($intent->id, $intent->digest);
        $this->authority()->go($intent->id, $intent->digest, '1', 'GO');

        return [$this->authority()->consume($intent->id, $scope), $scope];
    }

    public function test_acp_authority_refuses_another_provider_and_a_second_host(): void
    {
        [$intent] = $this->readyAcp();
        $this->refuses(fn () => ApprovedPrompt::acquire($intent->id, 'codex', $this->authority(), app(RunScopeFactory::class)));
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->acp_host_claimed_at);
        Http::assertNothingSent();
        ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class));
        $this->assertNotNull($intent->fresh()->acp_host_claimed_at);
        $this->refuses(fn () => ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class)));
    }

    public function test_acp_authority_binds_workspace_mcp_servers_resume_and_one_session(): void
    {
        [$intent] = $this->readyAcp();
        $approval = ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class));
        $session = ['cwd' => config('acp.workspace'), 'mcpServers' => []];
        $this->refuses(fn () => $approval->claimSession([...$session, 'cwd' => 'other-workspace']));
        $this->refuses(fn () => $approval->claimSession([...$session, 'mcpServers' => [['name' => 'unapproved']]]));
        $this->refuses(fn () => $approval->claimSession([...$session, 'resumeSessionId' => 'other-conversation']));
        $approval->claimSession($session);
        $this->refuses(fn () => $approval->claimSession($session));
    }

    public function test_acp_authority_allows_one_exact_text_prompt_and_refuses_blocks_changes_and_replay(): void
    {
        [$intent] = $this->readyAcp();
        $approval = ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class));
        $approval->claimSession(['cwd' => config('acp.workspace'), 'mcpServers' => []]);
        $inner = new AuthorityFixtureDriver;
        $driver = new ApprovedDriver($inner, $approval);
        $this->refuses(fn () => $driver->prompt('changed'));
        $this->refuses(fn () => $driver->prompt([(object) ['type' => 'text', 'text' => 'Owned ACP prompt']]));
        $this->assertSame([], $inner->calls);
        $driver->prompt('Owned ACP prompt');
        $this->assertSame(['prompt'], $inner->calls);
        $this->assertNotNull($intent->fresh()->acp_prompt_claimed_at);
        $this->refuses(fn () => $driver->prompt('Owned ACP prompt'));
        $this->assertSame(['prompt'], $inner->calls);
    }

    public function test_acp_authority_limits_model_changes_but_preserves_free_cancellation_and_discovery(): void
    {
        [$intent] = $this->readyAcp();
        $approval = ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class));
        $approval->claimSession(['cwd' => config('acp.workspace'), 'mcpServers' => []]);
        $inner = new AuthorityFixtureDriver;
        $driver = new ApprovedDriver($inner, $approval);
        $this->refuses(fn () => $driver->setModel('unapproved-model'));
        $driver->setModel('owned-acp-model')->await();
        $this->authority()->revoke($intent->id);
        Event::dispatch(new WorkerStopping);
        $this->assertSame('owned-acp-model', $driver->modelState()->await()->currentModelId);
        $driver->cancel()->await();
        $driver->stop(123)->await();
        $driver->endInput();
        $driver->kill();
        $this->assertNull($driver->processTree());
        $this->assertSame('owned-session', $driver->cliSessionId());
        $this->assertSame(['APP_KEY', 'DB_PASSWORD'], $driver->withheldCredentials());
        $this->assertSame(['set-model', 'models', 'cancel', 'stop:123', 'end-input', 'kill', 'process-tree', 'session-id', 'credentials'], $inner->calls);
    }

    public function test_acp_authority_rechecks_revocation_before_the_native_prompt(): void
    {
        [$intent] = $this->readyAcp();
        $approval = ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class));
        $approval->claimSession(['cwd' => config('acp.workspace'), 'mcpServers' => []]);
        $inner = new AuthorityFixtureDriver;
        $driver = new ApprovedDriver($inner, $approval);
        $this->authority()->revoke($intent->id);
        $this->refuses(fn () => $driver->prompt('Owned ACP prompt'));
        $this->assertSame([], $inner->calls);
        $this->assertNull($intent->fresh()->acp_prompt_claimed_at);
    }

    public function test_acp_authority_preserves_the_native_failure_object_and_does_not_retry_a_spent_prompt(): void
    {
        [$intent] = $this->readyAcp();
        $approval = ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class));
        $approval->claimSession(['cwd' => config('acp.workspace'), 'mcpServers' => []]);
        $inner = new AuthorityFixtureDriver;
        $inner->failure = new \RuntimeException('synthetic-native-driver-failure');
        $driver = new ApprovedDriver($inner, $approval);
        try {
            $driver->prompt('Owned ACP prompt');
            $this->fail('The native driver did not fail.');
        } catch (\RuntimeException $failure) {
            $this->assertSame($inner->failure, $failure);
        }
        $this->refuses(fn () => $driver->prompt('Owned ACP prompt'));
        $this->assertSame(['prompt'], $inner->calls);
    }

    public function test_acp_authority_rejects_a_changed_stored_continuation_before_claiming_the_host(): void
    {
        $prior = AcpTurn::query()->create(['status' => AcpTurn::FINISHED, 'provider' => 'claude', 'model' => 'owned-acp-model',
            'prompt' => 'Previous owned prompt', 'cli_session_id' => 'owned-provider-session', 'updates' => [], 'history_updates' => []]);
        [$intent] = $this->readyAcp(['continue' => $prior->id]);
        $prior->forceFill(['prompt' => 'Changed after approval'])->save();
        $this->refuses(fn () => ApprovedPrompt::acquire($intent->id, 'claude', $this->authority(), app(RunScopeFactory::class)));
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->acp_host_claimed_at);
        Http::assertNothingSent();
    }

    public function test_acp_authority_requires_a_known_model_before_an_intent_can_be_approved(): void
    {
        config(['acp.codex.model' => '']);
        $this->expectException(ValidationException::class);
        app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', ['provider' => 'codex', 'prompt' => 'Owned ACP prompt']);
    }

    /** @return array{LabRunIntent, RunScope} */
    private function readyAcp(array $extra = []): array
    {
        $scope = app(RunScopeFactory::class)->fromAction('acp.send', 'lab:agent', [
            'provider' => 'claude', 'model' => 'owned-acp-model', 'prompt' => 'Owned ACP prompt', ...$extra,
        ]);
        $intent = $this->authority()->draft($scope, '1');
        $this->authority()->approve($intent->id, $intent->digest);
        $this->authority()->go($intent->id, $intent->digest, '1', 'GO');

        return [$intent, $scope];
    }

    public function test_paid_work_cannot_be_minted_without_the_active_approved_parent(): void
    {
        $this->refuses(fn () => app(PaidWorkRegistry::class)->bind('overseer.turn', 'owned-row', ['prompt' => 'Owned work']));
        $this->assertSame(0, LabPaidWork::query()->count());
        Http::assertNothingSent();
    }

    public function test_paid_work_initial_payload_and_kind_must_match_the_approved_action(): void
    {
        [$intent, $scope] = $this->activatedWorkScope();
        app(PaidRunContext::class)->within($intent->id, $scope, function (): void {
            $registry = app(PaidWorkRegistry::class);
            $this->refuses(fn () => $registry->bind('overseer.turn', 'owned-row', ['prompt' => 'Different work']));
            $this->refuses(fn () => $registry->bind('acp.turn', 'owned-row', ['prompt' => 'Owned work']));
            $this->refuses(fn () => $registry->bind('overseer.turn', 'owned-row', []));
        });
        $this->assertSame(0, LabPaidWork::query()->count());
    }

    public function test_paid_work_binding_is_idempotent_without_allowing_payload_substitution(): void
    {
        [$intent, $scope] = $this->activatedWorkScope();
        app(PaidRunContext::class)->within($intent->id, $scope, function (): void {
            $registry = app(PaidWorkRegistry::class);
            $first = $registry->bind('overseer.turn', 'owned-row', ['prompt' => 'Owned work']);
            $second = $registry->bind('overseer.turn', 'owned-row', ['prompt' => 'Owned work']);
            $this->assertSame($first->id, $second->id);
            $this->assertNull($first->claimed_at);
            $this->refuses(fn () => $registry->bind('overseer.turn', 'owned-row', ['prompt' => 'Changed work']));
        });
        $this->assertSame(1, LabPaidWork::query()->count());
    }

    public function test_paid_work_changed_actual_payload_or_stored_binding_refuses_before_execution(): void
    {
        [$intent, $scope, $work] = $this->boundWork();
        $registry = app(PaidWorkRegistry::class);
        $calls = 0;
        $this->refuses(fn () => $registry->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Changed work'], function () use (&$calls): void {
            $calls++;
        }));
        $work->forceFill(['payload' => ['prompt' => 'Changed stored work']])->save();
        $this->refuses(fn () => $registry->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], function () use (&$calls): void {
            $calls++;
        }));
        $this->assertSame(0, $calls);
        $this->assertNull($work->fresh()->claimed_at);
    }

    public function test_paid_work_missing_reference_never_runs_the_callback(): void
    {
        $calls = 0;
        $this->refuses(fn () => app(PaidWorkRegistry::class)->run(null, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], function () use (&$calls): void {
            $calls++;
        }));
        $this->assertSame(0, $calls);
        Http::assertNothingSent();
    }

    public function test_paid_work_executes_only_once_in_the_real_native_worker_queue_without_an_owner_session(): void
    {
        [$intent, $scope, $work] = $this->boundWork();
        $this->beginNativeWork();
        Auth::guard('web')->logout();
        $calls = 0;
        $callback = function () use (&$calls): string {
            app(PaidRunContext::class)->requireActions(['agent.send']);
            app(PaidRunContext::class)->requireActions(['agent.send']);
            $calls++;

            return 'owned-result';
        };
        $registry = app(PaidWorkRegistry::class);
        $this->assertSame('owned-result', $registry->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], $callback));
        $this->assertNotNull($work->fresh()->claimed_at);
        $this->refuses(fn () => $registry->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], $callback));
        $this->assertSame(1, $calls);
        $this->assertGuest('web');
        $this->refuses(fn () => app(PaidRunContext::class)->requireActions(['agent.send']));
    }

    public function test_paid_work_free_discovery_can_retry_before_the_single_paid_claim(): void
    {
        [$intent, $scope, $work] = $this->boundWork();
        $this->beginNativeWork();
        $registry = app(PaidWorkRegistry::class);
        $this->assertSame('listing-unavailable', $registry->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], fn () => 'listing-unavailable'));
        $this->assertNull($work->fresh()->claimed_at);
        $registry->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], fn () => app(PaidRunContext::class)->requireActions(['agent.send']));
        $this->assertNotNull($work->fresh()->claimed_at);
    }

    public function test_paid_work_cannot_borrow_an_idle_heartbeat_without_native_execution(): void
    {
        [$intent, $scope, $work] = $this->boundWork();
        $calls = 0;
        try {
            app(PaidWorkRegistry::class)->run($work->id, 'overseer.turn', 'owned-row', ['prompt' => 'Owned work'], function () use (&$calls): void {
                $calls++;
            });
            $this->fail('Idle capacity was treated as actual job execution.');
        } catch (SessionNotReady) {
            $this->assertSame(0, $calls);
            $this->assertNull($work->fresh()->claimed_at);
        }
    }

    /** @return array{LabRunIntent, RunScope} */
    private function activatedWorkScope(): array
    {
        $scope = app(RunScopeFactory::class)->fromAction('agent.send', 'lab:agent', ['message' => 'Owned work']);
        $intent = $this->authority()->draft($scope, '1');
        $this->authority()->approve($intent->id, $intent->digest);
        $this->authority()->go($intent->id, $intent->digest, '1', 'GO');

        return [$this->authority()->consume($intent->id, $scope), $scope];
    }

    /** @return array{LabRunIntent, RunScope, LabPaidWork} */
    private function boundWork(): array
    {
        [$intent, $scope] = $this->activatedWorkScope();
        $work = app(PaidRunContext::class)->within($intent->id, $scope,
            fn () => app(PaidWorkRegistry::class)->bind('overseer.turn', 'owned-row', ['prompt' => 'Owned work']));

        return [$intent, $scope, $work];
    }

    private function beginNativeWork(): void
    {
        $queue = $this->app['queue']->connection('database');
        $queue->pushRaw('{"job":"owned-paid-work-event-only","data":{}}', 'default');
        $job = $queue->pop('default');
        $this->assertInstanceOf(DatabaseJob::class, $job);
        Event::dispatch(new JobProcessing('database', $job));
    }

    private function authority(): RunAuthority
    {
        return app(RunAuthority::class);
    }

    private function scope(): RunScope
    {
        return new RunScope('team.ask', 'lab:agent', ['message' => 'owned question']);
    }

    private function ready(string $cap = '1'): LabRunIntent
    {
        $intent = $this->authority()->draft($this->scope(), $cap);
        $this->authority()->approve($intent->id, $intent->digest);

        return $this->authority()->go($intent->id, $intent->digest, $cap, 'GO');
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unapproved or mismatched paid work was accepted.');
        } catch (PaidRunNotAuthorised $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }
}

/** No native CLI, process, provider or credential is used by this interface fixture. */
final class AuthorityFixtureDriver implements AgentDriver, CancelsTurns, OwnsProcessTree, ReportsModels
{
    public array $calls = [];

    public ?\RuntimeException $failure = null;

    public function start(): void
    {
        $this->calls[] = 'start';
    }

    public function prompt(string|array $content): void
    {
        $this->calls[] = 'prompt';
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function modelState(): Deferred
    {
        $this->calls[] = 'models';

        return $this->resolved((object) ['currentModelId' => 'owned-acp-model', 'availableModels' => []]);
    }

    public function setModel(string $modelId): Deferred
    {
        $this->calls[] = 'set-model';

        return $this->resolved();
    }

    public function cancel(): Deferred
    {
        $this->calls[] = 'cancel';

        return $this->resolved();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        $this->calls[] = 'stop:'.$graceMs;

        return $this->resolved();
    }

    public function processTree(): ?ProcessTree
    {
        $this->calls[] = 'process-tree';

        return null;
    }

    public function endInput(): void
    {
        $this->calls[] = 'end-input';
    }

    public function kill(): void
    {
        $this->calls[] = 'kill';
    }

    public function cliSessionId(): ?string
    {
        $this->calls[] = 'session-id';

        return 'owned-session';
    }

    public function withheldCredentials(): array
    {
        $this->calls[] = 'credentials';

        return ['APP_KEY', 'DB_PASSWORD'];
    }

    private function resolved(mixed $value = null): Deferred
    {
        $deferred = new Deferred;
        $deferred->resolve($value);

        return $deferred;
    }
}
