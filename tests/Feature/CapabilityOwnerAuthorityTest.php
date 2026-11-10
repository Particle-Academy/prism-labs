<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\CapabilityManager;
use App\Lab\LabSession;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\PrismHarness;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

final class CapabilityOwnerAuthorityTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
        $this->authorityFixtureOwner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
    }

    #[DataProvider('unapprovedOwners')]
    public function test_existing_browser_attachment_does_not_bypass_owner_and_policy(string $case): void
    {
        $participant = $case === 'other-participant' ? User::factory()->create() : $this->authorityFixtureOwner;
        $scope = $case === 'other-scope' ? 'other-scope' : 'lab:agent';
        $session = app(PrismHarness::class)->for($participant)->session($scope);
        $session->usingCapability('browser', ['id' => 'owned-existing-browser', 'state' => 'open']);
        if ($case === 'guest') {
            Auth::guard('web')->logout();
        }
        if ($case === 'disabled') {
            config(['capabilities.browser.enabled' => false]);
        }
        $this->refuses(fn () => app(CapabilityManager::class)->openBrowser($session));
        Http::assertNothingSent();
    }

    public static function unapprovedOwners(): array
    {
        return [['guest'], ['other-participant'], ['other-scope'], ['disabled']];
    }

    public function test_owner_can_keep_an_existing_attachment_without_new_network_or_go(): void
    {
        $session = app(LabSession::class)->resolveScope('lab:agent');
        $state = ['id' => 'owned-existing-browser', 'state' => 'open'];
        $session->usingCapability('browser', $state);
        $this->assertSame($state, app(CapabilityManager::class)->openBrowser($session));
        Http::assertNothingSent();
    }

    #[DataProvider('otherOperations')]
    public function test_direct_capability_mutations_require_the_native_owner(string $operation): void
    {
        $session = app(LabSession::class)->resolveScope('lab:agent');
        Auth::guard('web')->logout();
        $manager = app(CapabilityManager::class);
        $this->refuses(fn () => match ($operation) {
            'close' => $manager->closeBrowser($session),
            'detach' => $manager->detachHumanPlus($session),
            'attach' => $manager->attachHumanPlus($session, new SurfaceInvitation('https://owned-relay.test', 'owned-session',
                str_repeat('owned-token-', 3), 'owned-surface', 'owned-app')),
        });
        Http::assertNothingSent();
    }

    public static function otherOperations(): array
    {
        return [['close'], ['detach'], ['attach']];
    }

    public function test_disabled_capabilities_offer_no_tools_and_free_owner_cleanup_remains_available(): void
    {
        $session = app(LabSession::class)->resolveScope('lab:agent');
        $session->usingCapability('browser', ['id' => 'owned-no-sidecar']);
        $session->usingCapability('human_plus', ['id' => 'owned-no-relay']);
        config(['capabilities.browser.enabled' => false, 'capabilities.human_plus.enabled' => false]);
        $manager = app(CapabilityManager::class);
        $this->assertSame([], $manager->tools($session));
        // Explicitly drop fixture metadata: no external attachment was created.
        $session->forgetCapability('browser')->forgetCapability('human_plus');
        $manager->closeBrowser($session);
        $manager->detachHumanPlus($session);
        $this->assertNull($session->capability('browser'));
        $this->assertNull($session->capability('human_plus'));
        Http::assertNothingSent();
    }

    private function refuses(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('A capability operation escaped its actual owner or enabled policy.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }
}
