<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BenchmarkSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OwnerLoginBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-owner-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'owned-login-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'owned-login-csrf');
    }

    public function test_only_the_configured_owner_can_log_in_with_native_credentials(): void
    {
        $owner = $this->owner();
        User::factory()->create(['password' => self::PASSWORD]);
        $oldSession = session()->getId();
        $this->post('/lab/owner/login', [
            'email' => $owner->email, 'password' => self::PASSWORD,
            'approved' => true, 'owner' => 'Wish', 'redirect' => 'https://invalid.example',
        ])->assertRedirect('/lab/owner');
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame(2, User::query()->count());
        $this->assertSame(0, BenchmarkSpec::query()->count());
        $this->assertNull(session('approved_run_id'));
        Http::assertNothingSent();
    }

    public function test_valid_credentials_for_another_user_do_not_create_a_session(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create(['password' => self::PASSWORD]);
        $this->postJson('/lab/owner/login', [
            'email' => $other->email, 'password' => self::PASSWORD,
            'owner_user_id' => $owner->id, 'approved_by' => $owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
        $this->assertSame(2, User::query()->count());
        Http::assertNothingSent();
    }

    public function test_bad_password_and_unknown_email_have_the_same_fixed_response(): void
    {
        $owner = $this->owner();
        $badPassword = $this->postJson('/lab/owner/login', [
            'email' => $owner->email, 'password' => 'wrong-synthetic-password',
        ])->assertUnprocessable()->json('errors');
        $unknownEmail = $this->postJson('/lab/owner/login', [
            'email' => 'absent@fixture.invalid', 'password' => self::PASSWORD,
        ])->assertUnprocessable()->json('errors');
        $this->assertSame($badPassword, $unknownEmail);
        $this->assertGuest('web');
    }

    #[DataProvider('invalidConfiguration')]
    public function test_missing_or_malformed_owner_configuration_cannot_bootstrap_an_owner(mixed $value): void
    {
        $owner = $this->owner();
        config(['lab_authority.owner_user_id' => $value]);
        $this->postJson('/lab/owner/login', [
            'email' => $owner->email, 'password' => self::PASSWORD,
        ])->assertForbidden();
        $this->assertGuest('web');
        $this->assertSame(1, User::query()->count());
        Http::assertNothingSent();
    }

    public static function invalidConfiguration(): array
    {
        return [[null], [false], ['01'], [['id' => 1]]];
    }

    public function test_forwarded_addresses_cannot_reset_the_socket_peer_login_limit(): void
    {
        $owner = $this->owner();
        for ($i = 1; $i <= 5; $i++) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.$i)
                ->postJson('/lab/owner/login', ['email' => $owner->email, 'password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->withHeader('X-Forwarded-For', '198.51.100.99')
            ->postJson('/lab/owner/login', ['email' => $owner->email, 'password' => self::PASSWORD])
            ->assertStatus(429);
        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_login_requires_native_csrf_even_with_correct_credentials(): void
    {
        $owner = $this->owner();
        $this->withHeader('X-CSRF-TOKEN', 'incorrect')->postJson('/lab/owner/login', [
            'email' => $owner->email, 'password' => self::PASSWORD,
        ])->assertStatus(419);
        $this->assertGuest('web');
    }

    public function test_logout_invalidates_the_authenticated_session_and_rotates_csrf(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'web');
        $oldSession = session()->getId();
        $oldToken = session()->token();
        $this->post('/lab/owner/logout')->assertRedirect('/lab/owner');
        $this->assertGuest('web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        Http::assertNothingSent();
    }

    public function test_a_remote_socket_cannot_spoof_loopback_for_login(): void
    {
        $owner = $this->owner();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeader('X-Forwarded-For', '127.0.0.1')
            ->postJson('/lab/owner/login', ['email' => $owner->email, 'password' => self::PASSWORD])
            ->assertNotFound();
        $this->assertGuest('web');
    }

    public function test_the_owner_page_does_not_provision_a_user_or_disclose_configuration(): void
    {
        config(['lab_authority.owner_user_id' => null]);
        $response = $this->get('/lab/owner')->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Lab/Owner')
            ->where('configured', false)->where('isOwner', false));
        $this->assertSame(0, User::query()->count());
        Http::assertNothingSent();
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['password' => self::PASSWORD]);
        config(['lab_authority.owner_user_id' => $owner->id]);

        return $owner;
    }
}
