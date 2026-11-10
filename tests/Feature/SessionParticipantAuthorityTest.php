<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\LabSession;
use App\Lab\PaidRunContext;
use App\Lab\RunAuthority;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

final class SessionParticipantAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    public function test_missing_owner_does_not_provision_a_fallback_participant(): void
    {
        $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_configured_owner_is_not_a_login_for_a_guest(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent'));
        $this->assertSame(1, User::query()->count());
        $this->assertGuest('web');
    }

    public function test_native_owner_reads_their_own_stable_session_without_new_users(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        $sessions = app(LabSession::class);
        $one = $sessions->resolve(Request::create('/lab/agent', parameters: ['scope' => 'untrusted', 'owner_id' => 999]));
        $two = $sessions->resolveScope('lab:agent');
        $this->assertSame($owner->id, $one->participant()->getKey());
        $this->assertSame('lab:agent', $one->scope());
        $this->assertSame($one->key(), $two->key());
        $this->assertSame(1, User::query()->count());
    }

    public function test_wrong_authenticated_actor_cannot_select_the_configured_owner(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs(User::factory()->create(), 'web');
        $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent'));
        $this->assertSame(2, User::query()->count());
    }

    public function test_approved_guest_work_uses_its_owner_without_logging_in_or_provisioning(): void
    {
        $session = $this->withApprovedLabRun('team.ask', ['question' => 'Owned fixture'],
            fn () => app(LabSession::class)->resolveScope('lab:agent'));
        $this->assertSame($this->authorityFixtureOwner->id, $session->participant()->getKey());
        $this->assertGuest('web');
        $this->assertSame(1, User::query()->count());
        $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent'));
    }

    public function test_a_revoked_reference_cannot_be_used_to_resolve_a_guest_participant(): void
    {
        [$intent, $scope] = $this->approvedLabRun('team.ask', ['question' => 'Owned fixture']);
        app(RunAuthority::class)->consume($intent->id, $scope);
        $intent->forceFill(['revoked_at' => now()])->save();
        app(PaidRunContext::class)->within($intent->id, $scope,
            fn () => $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent')));
        $this->assertSame(1, User::query()->count());
    }

    public function test_wrong_login_cannot_be_replaced_by_an_approved_worker_owner(): void
    {
        $this->withApprovedLabRun('team.ask', ['question' => 'Owned fixture'], function (): void {
            $this->actingAs(User::factory()->create(), 'web');
            $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent'));
        });
    }

    public function test_deleted_owner_is_not_resurrected_from_a_cached_login(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        $owner->delete();
        $this->refuses(fn () => app(LabSession::class)->resolveScope('lab:agent'));
        $this->assertSame(0, User::query()->count());
    }

    private function refuses(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Session resolution granted implicit participant authority.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }
}
