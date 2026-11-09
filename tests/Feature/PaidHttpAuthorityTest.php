<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\LabRunIntent;
use App\Models\OverseerTurn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaidHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token' => 'owned-http-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-http-csrf');
        Queue::fake();
    }

    #[DataProvider('paidEntrypoints')]
    public function test_a_guest_cannot_start_paid_or_delegated_work(string $path): void
    {
        $this->postJson($this->path($path), ['owner' => 'Wish', 'approved' => true, 'go' => true])->assertForbidden();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('paidEntrypoints')]
    public function test_an_authenticated_owner_still_needs_an_approved_run_reference(string $path): void
    {
        $this->owner();
        $this->postJson($this->path($path), ['owner' => 'Wish', 'approved' => true, 'go' => true])
            ->assertForbidden()->assertJsonPath('code', 'paid_call_not_authorised');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function paidEntrypoints(): array
    {
        return array_map(fn ($path) => [$path], [
            '/lab/provider-probes/research', '/lab/provider-probes/cache', '/lab/consensus', '/lab/team/ask',
            '/lab/tasks/agent', '/lab/benchmarks/policy-turn', '/lab/chat', '/lab/agent', '/lab/agent/voice',
            '/lab/acp', '/lab/tests', '/lab/benchmarks/{spec}/launch', '/lab/benchmarks/compaction-probe',
            '/lab/benchmarks/compaction-recall', '/lab/benchmarks/summary-loss', '/lab/team/nudge', '/lab/evidence/send',
        ]);
    }

    public function test_policy_turn_keeps_csrf_protection_before_authority_or_provider_work(): void
    {
        $this->owner();
        $this->withHeader('X-CSRF-TOKEN', 'wrong')->postJson('/lab/benchmarks/policy-turn', [
            'provider' => 'anthropic', 'model' => 'synthetic', 'policy' => 'fixture', 'tools' => [], 'messages' => [],
        ])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_an_intent_draft_does_not_accept_submitted_approval_or_go_fields(): void
    {
        $owner = $this->owner();
        $response = $this->postJson('/lab/run-intents', [
            'action' => 'agent.send', 'resource' => 'lab:agent', 'payload' => ['message' => 'Owned question'],
            'cap_usd' => '1', 'approved_by' => $owner->id, 'approved' => true, 'go' => true,
        ])->assertCreated()->assertJsonPath('status', 'draft');
        $intent = LabRunIntent::query()->findOrFail($response->json('id'));
        $this->assertNull($intent->approved_by);
        $this->assertNull($intent->go_by);
        $this->postJson('/lab/agent', ['message' => 'Owned question', 'approved_run_id' => $intent->id])->assertForbidden();
        $this->assertSame(0, OverseerTurn::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_only_the_owner_can_draft_approve_go_or_revoke_an_intent(): void
    {
        $owner = $this->owner();
        $authority = app(RunAuthority::class);
        $intent = $authority->draft(app(RunScopeFactory::class)->fromAction('agent.send', 'lab:agent', ['message' => 'Owned question']), '1');
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['approve', 'go', 'revoke'] as $operation) {
            $this->postJson('/lab/run-intents/'.$intent->id.'/'.$operation, [
                'digest' => $intent->digest, 'cap_usd' => '1', 'confirmation' => 'GO', 'approved_by' => $owner->id,
            ])->assertForbidden();
        }
        $this->postJson('/lab/run-intents', ['action' => 'agent.send', 'resource' => 'lab:agent', 'payload' => [], 'cap_usd' => '1'])->assertForbidden();
        $this->assertSame('draft', $intent->fresh()->status);
        $this->assertSame(1, LabRunIntent::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_ready_intent_does_not_authorize_changed_payload_or_runtime_configuration(): void
    {
        $this->owner();
        $authority = app(RunAuthority::class);
        $intent = $authority->draft(app(RunScopeFactory::class)->fromAction('agent.send', 'lab:agent', ['message' => 'Owned question']), '1');
        $this->postJson('/lab/run-intents/'.$intent->id.'/approve', ['digest' => $intent->digest])->assertOk();
        $this->postJson('/lab/run-intents/'.$intent->id.'/go', ['digest' => $intent->digest, 'cap_usd' => '1', 'confirmation' => 'GO'])->assertOk();
        $this->postJson('/lab/agent', ['message' => 'Different question', 'approved_run_id' => $intent->id])->assertForbidden();
        config(['team.coordinator.model' => 'different-model']);
        $this->postJson('/lab/agent', ['message' => 'Owned question', 'approved_run_id' => $intent->id])->assertForbidden();
        $this->assertSame('ready', $intent->fresh()->status);
        $this->assertSame(0, OverseerTurn::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_conflicting_header_and_body_references_refuse_before_preflight(): void
    {
        $this->owner();
        $this->withHeader('X-Lab-Run-Id', '00000000-0000-4000-8000-000000000001')->postJson('/lab/agent', [
            'message' => 'Owned question', 'approved_run_id' => '00000000-0000-4000-8000-000000000002',
        ])->assertForbidden();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner, 'web');

        return $owner;
    }

    private function path(string $path): string
    {
        if (! str_contains($path, '{spec}')) {
            return $path;
        }
        $spec = app(BenchmarkDesigner::class)->draft('Owned fixture', 'cli', 'standard',
            ['prompt' => 'Owned fixture'], [],
            [['language' => 'php', 'harness' => 'prism', 'provider' => 'anthropic', 'model' => 'owned-model']],
            ['max_cost_usd' => 1]);

        return str_replace('{spec}', $spec->id, $path);
    }
}
