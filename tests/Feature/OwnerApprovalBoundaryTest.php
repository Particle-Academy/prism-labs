<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Models\BenchmarkSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OwnerApprovalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        app('router')->getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'owned-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-csrf');
        Http::preventStrayRequests();
    }

    public function test_a_guest_owner_label_cannot_approve_a_benchmark(): void
    {
        $spec = $this->awaitingSpec();
        $this->postJson('/lab/benchmarks/'.$spec->id.'/approve', [
            'owner' => 'Wish', 'approved' => true, 'approved_by' => 1,
        ])->assertForbidden();
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_authenticated_different_user_cannot_approve(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($other);
        $spec = $this->awaitingSpec();
        $this->postJson('/lab/benchmarks/'.$spec->id.'/approve', [
            'owner' => 'Wish', 'approved_by' => $owner->id,
        ])->assertForbidden();
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_even_the_owner_cannot_approve_a_changed_frozen_digest(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner);
        $spec = $this->awaitingSpec();
        $spec->forceFill(['lane_matrix' => [
            ['language' => 'php', 'harness' => 'prism', 'provider' => 'anthropic', 'model' => 'changed-owned-model'],
        ]])->save();
        $this->postJson('/lab/benchmarks/'.$spec->id.'/approve')->assertUnprocessable();
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_respec_exposes_no_approval_capability(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('benchmark:respec', $commands);
        $this->assertFalse($commands['benchmark:respec']->getDefinition()->hasOption('approve'));
    }

    public function test_approval_records_the_authenticated_owner_and_actual_digest(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config(['lab_authority.owner_user_id' => (string) $owner->id]);
        $this->actingAs($owner, 'web');
        $spec = $this->awaitingSpec();
        $this->postJson('/lab/benchmarks/'.$spec->id.'/approve', [
            'owner' => 'caller-label', 'approved_by' => $other->id,
            'approved_digest' => str_repeat('a', 64),
        ])->assertRedirect();
        $approved = $spec->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertSame($owner->id, $approved->approved_by);
        $this->assertSame($spec->digest, $approved->approved_digest);
        $this->assertNotNull($approved->approved_at);
        Http::assertNothingSent();
    }

    #[DataProvider('malformedOwnerConfiguration')]
    public function test_owner_configuration_never_coerces_untrusted_shapes(mixed $value): void
    {
        $user = User::factory()->create();
        config(['lab_authority.owner_user_id' => $value]);
        $this->actingAs($user, 'web');
        $spec = $this->awaitingSpec();
        $this->postJson('/lab/benchmarks/'.$spec->id.'/approve')->assertForbidden();
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
        Http::assertNothingSent();
    }

    public static function malformedOwnerConfiguration(): array
    {
        return [[null], [false], [0], [-1], ['0'], ['01'], [' 1 '], ['1e0'],
            [['id' => 1]], ['9999999999999999999']];
    }

    public function test_the_generic_operator_is_not_an_owner_fallback(): void
    {
        $operator = User::factory()->create(['email' => 'lab@prism.local']);
        config(['lab_authority.owner_user_id' => null]);
        $this->actingAs($operator, 'web');
        $spec = $this->awaitingSpec();
        $this->postJson('/lab/benchmarks/'.$spec->id.'/approve')->assertForbidden();
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
    }

    public function test_the_service_cannot_approve_without_the_web_owner(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $spec = $this->awaitingSpec();
        try {
            app(BenchmarkDesigner::class)->approve($spec);
            $this->fail('The service accepted an approval without an authenticated owner.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
    }

    public function test_owner_approval_keeps_native_csrf_protection(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        $spec = $this->awaitingSpec();
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/lab/benchmarks/'.$spec->id.'/approve')->assertStatus(419);
        $this->assertSame('awaiting_approval', $spec->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_respec_still_drafts_a_revision_without_approving_it(): void
    {
        $spec = $this->awaitingSpec();
        $this->artisan('benchmark:respec', ['name' => $spec->name, '--to' => 'owned-revision-model'])->assertSuccessful();
        $draft = BenchmarkSpec::query()->where('name', $spec->name)->orderByDesc('revision')->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertSame(2, $draft->revision);
        $this->assertNull($draft->approved_by);
        $this->assertNull($draft->approved_at);
        $this->assertNull($draft->approved_digest);
        Http::assertNothingSent();
    }

    private function awaitingSpec(): BenchmarkSpec
    {
        $designer = app(BenchmarkDesigner::class);

        return $designer->requestApproval($designer->draft(
            'owned-authority-fixture', 'cli', 'standard',
            ['prompt' => 'Owned fixture'], ['dimension' => 'correctness'],
            [['language' => 'php', 'harness' => 'prism', 'provider' => 'anthropic', 'model' => 'owned-model']],
            ['max_cost_usd' => 1],
        ));
    }
}
