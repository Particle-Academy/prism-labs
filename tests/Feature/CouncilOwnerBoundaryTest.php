<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsurePrismLabIsLocal;
use App\Http\Middleware\RequireCouncilOwner;
use App\Http\Middleware\RequireLabOwner;
use App\Models\User;
use ChangeCouncil\Facades\Council;
use ChangeCouncil\Http\Api;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CouncilOwnerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'owned-council-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-council-csrf');
    }

    public function test_the_configured_id_and_email_owner_can_read_the_native_ledger(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web')->getJson('/council/api/v1/changes')->assertOk()->assertExactJson(['changes' => []]);
        $this->assertTrue(Gate::forUser($owner)->allows('council-owner'));
        $this->get('/council')->assertOk()->assertInertia(fn ($page) => $page->component('Council/Index')->where('canUseCouncil', true));
        Http::assertNothingSent();
    }

    #[DataProvider('allCouncilRoutes')]
    public function test_every_native_route_refuses_a_guest(string $method, string $path): void
    {
        $this->owner();
        $this->json($method, $path, ['reason' => 'caller cannot decide'])->assertForbidden()->assertDontSee('PRIVATE-COUNCIL-FIXTURE');
        $this->assertSame(0, Change::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('allCouncilRoutes')]
    public function test_email_alone_cannot_give_another_id_access(string $method, string $path): void
    {
        $owner = $this->owner();
        $other = User::factory()->create();
        $other->forceFill(['email' => $owner->email]);
        $this->actingAs($other, 'web')->json($method, $path)->assertForbidden();
        $this->assertFalse(Gate::forUser($other)->allows('council-owner'));
        $this->assertSame(0, Change::query()->count());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidOwners')]
    public function test_owner_configuration_is_one_matching_email_or_no_access(mixed $emails): void
    {
        $owner = $this->owner();
        config(['council.owners' => $emails]);
        $this->actingAs($owner, 'web')->getJson('/council/api/v1/changes')->assertForbidden();
        $this->assertFalse(Gate::forUser($owner)->allows('council-owner'));
        Http::assertNothingSent();
    }

    public static function invalidOwners(): array
    {
        return [[[]], [null], [false], ['owner@fixture.invalid'], [['']], [['not-an-email']], [['another@fixture.invalid']], [['owner@fixture.invalid', 'another@fixture.invalid']], [['owner@fixture.invalid', 'OWNER@FIXTURE.INVALID']], [['owner' => 'owner@fixture.invalid']], [[['email' => 'owner@fixture.invalid']]]];
    }

    public function test_config_email_case_and_spaces_do_not_create_a_second_identity(): void
    {
        $owner = $this->owner();
        config(['council.owners' => [' OWNER@FIXTURE.INVALID ']]);
        $this->actingAs($owner, 'web')->getJson('/council/api/v1/changes')->assertOk();
        Http::assertNothingSent();
    }

    public function test_missing_numeric_owner_denies_even_a_configured_council_email(): void
    {
        $owner = $this->owner();
        config(['lab_authority.owner_user_id' => null]);
        $this->actingAs($owner, 'web')->getJson('/council/api/v1/feed')->assertForbidden();
        $this->assertFalse(Gate::forUser($owner)->allows('council-owner'));
    }

    public function test_a_deleted_or_changed_owner_is_not_cached_across_authorization_checks(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web');
        $this->getJson('/council/api/v1/changes')->assertOk();
        User::query()->whereKey($owner->id)->update(['email' => 'new@fixture.invalid']);
        $this->getJson('/council/api/v1/changes')->assertForbidden();
        $this->assertFalse(Gate::forUser($owner)->allows('council-owner'));
        $owner->delete();
        $this->getJson('/council/api/v1/feed')->assertForbidden();
    }

    public function test_a_remote_socket_cannot_use_forwarded_loopback_to_read_the_ledger(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web')->withServerVariables(['REMOTE_ADDR' => '198.51.100.19'])
            ->withHeader('X-Forwarded-For', '127.0.0.1')->getJson('/council/api/v1/changes')->assertNotFound();
    }

    public function test_owner_decisions_require_csrf_and_keep_the_verified_numeric_actor(): void
    {
        $owner = $this->owner();
        $change = Council::ledger()->propose([
            'title' => 'PRIVATE-COUNCIL-FIXTURE', 'summary' => 'Synthetic fixture only.',
            'approach' => 'Synthetic reviewed approach.', 'classes' => ['declared-breaking'],
            'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness']],
            'evidence' => [], 'links' => [], 'source' => 'agent_request',
            'initiator' => 'untrusted@fixture', 'pros' => 'Synthetic pro.', 'cons' => 'Synthetic con.',
        ]);
        $this->assertSame('awaiting_owner', $change->state);
        $this->actingAs($owner, 'web')->withHeader('X-CSRF-TOKEN', 'incorrect')
            ->postJson('/council/api/v1/changes/'.$change->ref.'/owner/approve-initiation')->assertStatus(419);
        $this->assertSame('awaiting_owner', $change->fresh()->state);
        $this->withHeader('X-CSRF-TOKEN', 'owned-council-csrf')
            ->postJson('/council/api/v1/changes/'.$change->ref.'/owner/approve-initiation', ['note' => 'Synthetic owner review.'])->assertOk();
        $actors = AuditEvent::query()->where('actor', 'like', 'owner:%')->pluck('actor')->all();
        $this->assertNotEmpty($actors);
        $this->assertSame(['owner:'.$owner->id], array_values(array_unique($actors)));
        $this->assertDatabaseCount('lab_run_intents', 0);
        Http::assertNothingSent();
    }

    public function test_all_twenty_one_package_routes_have_the_complete_host_boundary(): void
    {
        $routes = array_filter(Route::getRoutes()->getRoutesByName(), fn (string $name): bool => str_starts_with($name, 'council.'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(21, $routes);
        foreach ($routes as $name => $route) {
            $middleware = $route->gatherMiddleware();
            foreach (['web', EnsurePrismLabIsLocal::class, RequireLabOwner::class, RequireCouncilOwner::class, 'throttle:60,1'] as $guard) {
                $this->assertContains($guard, $middleware, $name.' lacks '.$guard);
            }
        }
        $this->assertFalse(config('council.routes.enabled'));
    }

    public function test_native_closed_bodies_and_size_limits_do_not_change_an_owner_decision(): void
    {
        $owner = $this->owner();
        $change = Council::ledger()->propose([
            'title' => 'Synthetic closed-body fixture', 'summary' => 'Synthetic only.', 'approach' => 'Synthetic approach.', 'classes' => ['declared-breaking'],
            'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness']], 'evidence' => [], 'links' => [],
            'source' => 'agent_request', 'initiator' => 'untrusted@fixture', 'pros' => 'pro', 'cons' => 'con',
        ]);
        $this->actingAs($owner, 'web');
        $path = '/council/api/v1/changes/'.$change->ref.'/owner/approve-initiation';
        $this->postJson($path, ['note' => 'fixture', 'approved_by' => 999])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_request');
        $this->postJson($path, ['note' => str_repeat('x', 65537)])->assertStatus(413);
        $this->assertSame('awaiting_owner', $change->fresh()->state);
        $this->assertSame(0, AuditEvent::query()->where('actor', 'like', 'owner:%')->count());
        Http::assertNothingSent();
    }

    public function test_native_actor_keeps_the_stable_owner_id_despite_email_spelling(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['email' => 'Owner@Fixture.Invalid'])->save();
        config(['council.owners' => ['owner@fixture.invalid']]);
        $change = Council::ledger()->propose([
            'title' => 'Synthetic actor fixture', 'summary' => 'Synthetic only.', 'approach' => 'Synthetic approach.', 'classes' => ['declared-breaking'],
            'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness']], 'evidence' => [], 'links' => [],
            'source' => 'agent_request', 'initiator' => 'untrusted@fixture', 'pros' => 'pro', 'cons' => 'con',
        ]);
        $this->actingAs($owner, 'web')->postJson('/council/api/v1/changes/'.$change->ref.'/owner/reject-initiation', ['reason' => 'Synthetic rejection.'])->assertOk();
        $this->assertSame(['owner:'.$owner->id], AuditEvent::query()->where('actor', 'like', 'owner:%')->pluck('actor')->unique()->values()->all());
        $this->assertSame('rejected', $change->fresh()->state);
        Http::assertNothingSent();
    }

    public static function allCouncilRoutes(): array
    {
        return [
            ['GET', '/council'], ['GET', '/council/changes/CC-prism-0001'],
            ['GET', '/council/api/v1/changes'], ['GET', '/council/api/v1/changes/CC-prism-0001'],
            ['GET', '/council/api/v1/changes/CC-prism-0001/rounds'], ['GET', '/council/api/v1/consumers'], ['GET', '/council/api/v1/feed'],
            ['POST', '/council/api/v1/changes/CC-prism-0001/owner/approve-initiation'], ['POST', '/council/api/v1/changes/CC-prism-0001/owner/reject-initiation'],
            ['POST', '/council/api/v1/changes/CC-prism-0001/owner/override'], ['POST', '/council/api/v1/changes/CC-prism-0001/owner/revoke'],
            ['PUT', '/council/api/v1/changes/CC-prism-0001/silence-policy'], ['PUT', '/council/api/v1/consumers/synthetic@fixture/silence-policy'],
            ['GET', '/council/members'], ['GET', '/council/members/synthetic@fixture'],
            ['GET', '/council/api/v1/members'], ['GET', '/council/api/v1/members/synthetic@fixture'],
            ['PUT', '/council/api/v1/members/synthetic@fixture/weight'], ['PUT', '/council/api/v1/members/synthetic@fixture/project'],
            ['POST', '/council/api/v1/members/synthetic@fixture/remove'], ['PUT', '/council/api/v1/settings/blocking-threshold'],
        ];
    }

    public function test_the_native_actor_hook_refuses_missing_session_or_a_different_principal_without_email_fallback(): void
    {
        $owner = $this->owner();
        $change = $this->pendingChange();
        $before = AuditEvent::query()->count();
        $api = app(Api::class);
        $denied = $api->rejectInitiation($owner, $change->ref, ['reason' => 'Synthetic only.']);
        $this->assertSame(403, $denied->status);
        // Isolate the actor hook as a second guard: even a permissive package
        // authorizer cannot supply a missing or inconsistent owner identity.
        Council::authorizeUsing(fn (): bool => true);
        $api = app(Api::class);
        $missing = $api->rejectInitiation($owner, $change->ref, ['reason' => 'Synthetic only.']);
        $this->assertSame(500, $missing->status);
        $this->assertSame('invalid_actor', $missing->body['error']['code']);
        $this->assertSame($before, AuditEvent::query()->count());
        $this->assertDatabaseCount('council_decisions', 0);
        $this->assertSame('awaiting_owner', $change->fresh()->state);

        $this->actingAs($owner, 'web');
        $other = User::factory()->create();
        $other->forceFill(['email' => $owner->email]);
        $mismatch = $api->rejectInitiation($other, $change->ref, ['reason' => 'Synthetic only.']);
        $this->assertSame(500, $mismatch->status);
        $this->assertSame('invalid_actor', $mismatch->body['error']['code']);
        $this->assertSame($before, AuditEvent::query()->count());
        $this->assertDatabaseCount('council_decisions', 0);
        $this->assertSame('awaiting_owner', $change->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_direct_native_api_reads_require_the_same_verified_owner_as_routes(): void
    {
        $owner = $this->owner();
        $this->assertSame(403, app(Api::class)->changes($owner, [])->status);
        $this->actingAs($owner, 'web');
        $this->assertSame(200, app(Api::class)->changes($owner, [])->status);
        $other = User::factory()->create();
        $other->forceFill(['email' => $owner->email]);
        $this->assertSame(403, app(Api::class)->changes($other, [])->status);
        $this->assertSame(403, app(Api::class)->memberList($other)->status);
        Http::assertNothingSent();
    }

    public function test_numeric_actor_survives_email_changes_and_keeps_historical_email_decisions_unchanged(): void
    {
        $owner = $this->owner();
        $legacy = $this->pendingChange();
        // A synthetic pre-upgrade record, created through the native ledger.
        Council::ledger()->rejectInitiation($legacy, 'historical@fixture.invalid', 'Synthetic historical decision.');
        $oldDecision = Decision::query()->sole();
        $oldAttributes = $oldDecision->getAttributes();
        $this->actingAs($owner, 'web');
        foreach (['owner@fixture.invalid', 'Updated@Fixture.Invalid'] as $email) {
            $owner->forceFill(['email' => $email])->save();
            config(['council.owners' => [strtolower($email)]]);
            $this->actingAs($owner->fresh(), 'web');
            $change = $this->pendingChange();
            $this->postJson('/council/api/v1/changes/'.$change->ref.'/owner/reject-initiation', ['reason' => 'Synthetic current decision.'])->assertOk();
            $this->assertSame('owner:'.$owner->id, Decision::query()->where('change_id', $change->id)->sole()->decided_by);
        }
        $this->assertSame($oldAttributes, $oldDecision->fresh()->getAttributes());
        $this->assertSame('owner:historical@fixture.invalid', $oldDecision->fresh()->decided_by);
        $this->assertDatabaseCount('lab_run_intents', 0);
        Http::assertNothingSent();
    }

    private function pendingChange(): Change
    {
        return Council::ledger()->propose([
            'title' => 'Synthetic actor fixture', 'summary' => 'Synthetic only.', 'approach' => 'Synthetic approach.', 'classes' => ['declared-breaking'],
            'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness']], 'evidence' => [], 'links' => [],
            'source' => 'agent_request', 'initiator' => 'untrusted@fixture', 'pros' => 'pro', 'cons' => 'con',
        ]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['email' => 'owner@fixture.invalid']);
        config(['lab_authority.owner_user_id' => $owner->id, 'council.owners' => [$owner->email], 'council.origin' => 'prism']);

        return $owner;
    }
}
