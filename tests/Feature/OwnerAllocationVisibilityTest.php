<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Lab\RunScope;
use App\Lab\WorkerHeartbeats;
use App\Models\LabAuthoritySession;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OwnerAllocationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'owned-allocation-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-allocation-csrf');
        $this->owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->owner->id]);
        $this->actingAs($this->owner, 'web');
        $this->directory = storage_path('framework/testing/owner-allocation-'.bin2hex(random_bytes(8)));
        config(['lab-preflight.workers.directory' => $this->directory.'/workers',
            'cache.stores.file.path' => $this->directory.'/cache', 'cache.stores.file.lock_path' => $this->directory.'/cache',
            'queue.default' => 'database', 'queue.connections.database.retry_after' => 90,
            'team.coordinator.provider' => 'anthropic', 'prism.providers.anthropic.api_key' => 'owned-allocation-key-canary']);
        Cache::forgetDriver('file');
        $this->app->forgetInstance(WorkerHeartbeats::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        if (isset($this->directory)) {
            app(Filesystem::class)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_reading_an_empty_allocation_creates_no_session_or_intent(): void
    {
        $this->getJson('/lab/run-intents')->assertOk()->assertExactJson([
            'allocated_microusd' => 0, 'available_microusd' => 5_000_000,
            'session_limit_microusd' => 5_000_000, 'intents' => [], 'next_before' => null,
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('lab_authority_sessions', 0);
        $this->assertDatabaseCount('lab_run_intents', 0);
        Http::assertNothingSent();
    }

    public function test_a_guest_cannot_read_allocations_with_an_owner_label(): void
    {
        $this->ready('1');
        Auth::guard('web')->logout();
        $this->getJson('/lab/run-intents?owner_user_id='.$this->owner->id)->assertForbidden();
        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_another_authenticated_user_cannot_read_the_configured_owners_ledger(): void
    {
        $this->ready('1');
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/lab/run-intents')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_read_service_itself_does_not_authorize_a_guest(): void
    {
        $this->ready('1');
        Auth::guard('web')->logout();
        try {
            app(RunAuthority::class)->allocations();
            $this->fail('A guest read the private allocation service.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        } finally {
            $this->assertGuest('web');
            Http::assertNothingSent();
        }
    }

    public function test_an_invalid_stored_allocation_is_not_reported_as_available_budget(): void
    {
        $intent = $this->ready('1');
        foreach ([-1, 5_000_001] as $amount) {
            LabAuthoritySession::query()->whereKey($intent->authority_session_id)->update(['allocated_microusd' => $amount]);
            $this->getJson('/lab/run-intents')->assertForbidden()->assertJsonPath('code', 'paid_call_not_authorised');
            $this->assertSame($amount, LabAuthoritySession::query()->whereKey($intent->authority_session_id)->value('allocated_microusd'));
        }
        Http::assertNothingSent();
    }

    public function test_the_owner_reads_only_whitelisted_metadata_and_no_other_session(): void
    {
        $intent = $this->ready('1.25');
        $other = User::factory()->create();
        $foreignSession = LabAuthoritySession::query()->create(['owner_user_id' => $other->id]);
        $foreign = $intent->replicate();
        $foreign->forceFill(['id' => (string) Str::uuid7(), 'authority_session_id' => $foreignSession->id])->save();
        $before = $intent->fresh()->toArray();
        $response = $this->getJson('/lab/run-intents')->assertOk()
            ->assertJsonPath('allocated_microusd', 1_250_000)->assertJsonPath('available_microusd', 3_750_000)
            ->assertJsonCount(1, 'intents')->assertJsonPath('intents.0.id', $intent->id)
            ->assertJsonPath('intents.0.status', 'ready')->assertJsonPath('intents.0.can_revoke', true);
        $keys = array_keys($response->json('intents.0'));
        sort($keys);
        $this->assertSame(['action', 'can_revoke', 'cap_microusd', 'consumed_at', 'expires_at', 'id', 'revoked_at', 'status'], $keys);
        foreach (['owned-private-allocation-prompt', 'owned-allocation-key-canary', $intent->digest, $foreign->id] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame($before, $intent->fresh()->toArray());
        $this->assertDatabaseCount('lab_authority_sessions', 2);
        Http::assertNothingSent();
    }

    public function test_an_expired_unused_allocation_is_visible_and_only_explicit_cancellation_releases_it(): void
    {
        $intent = $this->ready('5');
        $this->travel(16)->minutes();
        $this->getJson('/lab/run-intents')->assertOk()->assertJsonPath('allocated_microusd', 5_000_000)
            ->assertJsonPath('available_microusd', 0)->assertJsonPath('intents.0.id', $intent->id)
            ->assertJsonPath('intents.0.status', 'ready')->assertJsonPath('intents.0.can_revoke', true);
        $this->assertSame('ready', $intent->fresh()->status);
        $this->postJson('/lab/run-intents/'.$intent->id.'/revoke')->assertOk()->assertJsonPath('status', 'revoked');
        $this->getJson('/lab/run-intents')->assertOk()->assertJsonPath('allocated_microusd', 0)
            ->assertJsonPath('available_microusd', 5_000_000)->assertJsonPath('intents.0.can_revoke', false);
        $this->assertNotNull($intent->fresh()->revoked_at);
        Http::assertNothingSent();
    }

    public function test_repeated_cancellation_cannot_release_another_runs_allocation(): void
    {
        $first = $this->ready('1');
        $other = $this->ready('2');
        $this->postJson('/lab/run-intents/'.$first->id.'/revoke')->assertOk();
        $this->postJson('/lab/run-intents/'.$first->id.'/revoke')->assertOk();
        $this->getJson('/lab/run-intents')->assertOk()->assertJsonPath('allocated_microusd', 2_000_000);
        $this->assertSame('ready', $other->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancellation_of_started_work_retains_its_allocation_and_revokes_future_work(): void
    {
        $intent = $this->ready('1');
        $this->readyWorker();
        app(RunAuthority::class)->consume($intent->id, $this->scope());
        $this->assertSame('running', $intent->fresh()->status);
        Http::fake()->preventStrayRequests();
        $this->postJson('/lab/run-intents/'.$intent->id.'/revoke')->assertOk();
        $this->getJson('/lab/run-intents')->assertOk()->assertJsonPath('allocated_microusd', 1_000_000)
            ->assertJsonPath('available_microusd', 4_000_000)->assertJsonPath('intents.0.status', 'revoked');
        $this->assertNotNull($intent->fresh()->consumed_at);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(RunAuthority::class)->assertActive($intent->id, $this->scope());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cancellation_does_not_refund_a_tampered_cap_out_of_another_runs_allocation(): void
    {
        $first = $this->ready('1');
        $other = $this->ready('3');
        $first->forceFill(['cap_microusd' => 2_000_000])->save();
        $this->postJson('/lab/run-intents/'.$first->id.'/revoke')->assertForbidden()
            ->assertJsonPath('code', 'paid_call_not_authorised');
        $this->assertSame(4_000_000, LabAuthoritySession::query()->whereKey($first->authority_session_id)->value('allocated_microusd'));
        $this->assertSame('ready', $first->fresh()->status);
        $this->assertSame('ready', $other->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_foreign_intent_cannot_be_cancelled_even_with_its_valid_id(): void
    {
        $intent = $this->ready('1');
        $other = User::factory()->create();
        config(['lab_authority.owner_user_id' => $other->id]);
        $this->actingAs($other, 'web');
        $this->postJson('/lab/run-intents/'.$intent->id.'/revoke')->assertForbidden();
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertSame(1_000_000, LabAuthoritySession::query()->whereKey($intent->authority_session_id)->value('allocated_microusd'));
        Http::assertNothingSent();
    }

    public function test_owner_cancellation_requires_native_csrf_and_no_provider_preflight(): void
    {
        $intent = $this->ready('1');
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/lab/run-intents/'.$intent->id.'/revoke')->assertStatus(419);
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertSame(1_000_000, LabAuthoritySession::query()->sole()->allocated_microusd);
        Http::assertNothingSent();
    }

    public function test_owner_metadata_is_bounded_and_can_page_to_older_abandoned_intents_without_duplicates(): void
    {
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = app(RunAuthority::class)->draft($this->scope(), '1')->id;
        }
        $first = $this->getJson('/lab/run-intents')->assertOk()->assertJsonCount(20, 'intents');
        $second = $this->getJson('/lab/run-intents?before='.$first->json('next_before'))->assertOk()
            ->assertJsonCount(3, 'intents')->assertJsonPath('next_before', null);
        $seen = [...array_column($first->json('intents'), 'id'), ...array_column($second->json('intents'), 'id')];
        sort($seen);
        sort($ids);
        $this->assertSame($ids, $seen);
        $this->assertSame(23, count(array_unique($seen)));
        Http::assertNothingSent();
    }

    public function test_a_malformed_page_cursor_is_refused_before_loading_metadata(): void
    {
        $this->ready('1');
        $this->getJson('/lab/run-intents?before=not-a-native-reference')->assertUnprocessable()->assertJsonValidationErrors('before');
        Http::assertNothingSent();
    }

    public function test_owner_page_exposes_the_same_private_allocation_snapshot_only_to_the_owner(): void
    {
        $intent = $this->ready('1.25');
        $headers = ['X-Inertia' => 'true'];
        $version = app(HandleInertiaRequests::class)->version(HttpRequest::create('/lab/owner'));
        if ($version !== null) {
            $headers['X-Inertia-Version'] = $version;
        }
        $this->get('/lab/owner', $headers)->assertOk()
            ->assertJsonPath('props.allocations.allocated_microusd', 1_250_000)
            ->assertJsonPath('props.allocations.intents.0.id', $intent->id);
        Auth::guard('web')->logout();
        $response = $this->get('/lab/owner', $headers)->assertOk()->assertJsonPath('props.allocations', null);
        $this->assertStringNotContainsString($intent->id, $response->getContent());
        Http::assertNothingSent();
    }

    private function scope(): RunScope
    {
        return new RunScope('team.ask', 'lab:owner-ledger', ['message' => 'owned-private-allocation-prompt']);
    }

    private function ready(string $cap): LabRunIntent
    {
        $authority = app(RunAuthority::class);
        $intent = $authority->draft($this->scope(), $cap);
        $authority->approve($intent->id, $intent->digest);

        return $authority->go($intent->id, $intent->digest, $cap, 'GO');
    }

    private function readyWorker(): void
    {
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 60)));
        Http::fake(function (Request $request) {
            $this->assertStringStartsWith('https://api.github.com/repos/Particle-Academy/prism/', $request->url());
            $head = str_repeat('a', 40);
            $runs = array_map(fn ($path, $index) => ['id' => 100 + $index, 'run_number' => 1, 'path' => $path,
                'head_sha' => $head, 'head_branch' => 'main', 'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'],
                config('lab-preflight.core_actions.workflows'), array_keys(config('lab-preflight.core_actions.workflows')));

            return Http::response(str_contains($request->url(), '/git/ref/')
                ? ['ref' => 'refs/heads/main', 'object' => ['sha' => $head, 'type' => 'commit']]
                : ['total_count' => count($runs), 'workflow_runs' => $runs]);
        });
    }
}
