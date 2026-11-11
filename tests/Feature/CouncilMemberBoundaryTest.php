<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use ChangeCouncil\Census\Census;
use ChangeCouncil\Facades\Council;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Petitions\Ingest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CouncilMemberBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        $owner = User::factory()->create(['email' => 'member-owner@fixture.invalid']);
        config(['lab_authority.owner_user_id' => $owner->id, 'council.owners' => [$owner->email]]);
        $this->actingAs($owner, 'web')->withSession(['_token' => 'member-csrf'])->withHeader('X-CSRF-TOKEN', 'member-csrf');
    }

    public function test_owner_member_controls_require_csrf_validate_inputs_and_audit_numeric_identity(): void
    {
        $member = $this->member();
        $member = $member->fresh();
        $this->assertSame(1, $member->weight);
        $this->getJson('/council/api/v1/members')->assertOk()->assertJsonPath('blocking_threshold.value', 1)->assertJsonPath('members.0.address', $member->address);
        $this->get('/council/members')->assertOk()->assertInertia(fn ($page) => $page->component('Council/Members')->where('canSetPolicy', true));
        $this->get('/council/members/'.$member->address)->assertOk()->assertInertia(fn ($page) => $page->component('Council/Member')->where('detail.member.address', $member->address));
        $api = '/council/api/v1/members/'.$member->address;
        foreach ([
            ['PUT', $api.'/weight', ['weight' => 3]],
            ['PUT', $api.'/project', ['project_name' => 'Synthetic project']],
            ['POST', $api.'/remove', ['reason' => 'Synthetic removal']],
            ['PUT', '/council/api/v1/settings/blocking-threshold', ['threshold' => 2]],
        ] as [$method, $url, $body]) {
            $this->withHeader('X-CSRF-TOKEN', 'expired')->json($method, $url, $body)->assertStatus(419);
        }
        $this->assertSame(1, $member->fresh()->weight);
        $this->assertNull($member->fresh()->removed_at);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->withHeader('X-CSRF-TOKEN', 'member-csrf');
        foreach ([
            [$api.'/weight', ['weight' => -1], 'invalid_weight'],
            [$api.'/weight', ['weight' => '2'], 'invalid_weight'],
            [$api.'/weight', ['weight' => 1001], 'invalid_weight'],
            [$api.'/weight', ['weight' => 2, 'actor' => 'owner:999'], 'invalid_request'],
            [$api.'/project', ['project_url' => 'javascript:alert(1)'], 'invalid_url'],
            [$api.'/project', ['project_url' => '//fixture.invalid'], 'invalid_url'],
            [$api.'/project', ['weight' => 99], 'invalid_request'],
            ['/council/api/v1/settings/blocking-threshold', ['threshold' => 0], 'invalid_threshold'],
            ['/council/api/v1/settings/blocking-threshold', ['threshold' => 1.5], 'invalid_threshold'],
        ] as [$url, $body, $code]) {
            $this->putJson($url, $body)->assertUnprocessable()->assertJsonPath('error.code', $code);
        }
        $this->assertSame(0, AuditEvent::query()->count());
        $this->putJson($api.'/weight', ['weight' => 0])->assertOk()->assertJsonPath('member.weight', 0);
        $this->putJson($api.'/project', ['project_name' => '<script>PRIVATE-PROJECT</script>', 'project_description' => 'PRIVATE-DESCRIPTION', 'project_url' => 'https://fixture.invalid/project'])->assertOk();
        $this->putJson('/council/api/v1/settings/blocking-threshold', ['threshold' => 2])->assertOk()->assertJsonPath('blocking_threshold.value', 2);
        $actors = AuditEvent::query()->pluck('actor')->unique()->values()->all();
        $this->assertSame(['owner:'.auth('web')->id()], $actors);
        $projectAudit = AuditEvent::query()->where('type', 'consumer.project')->sole();
        $encoded = json_encode($projectAudit->data);
        $this->assertStringNotContainsString('PRIVATE-PROJECT', $encoded);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $encoded);
        $this->postJson($api.'/remove', ['reason' => 'Synthetic removal'])->assertOk();
        $this->assertNotNull($member->fresh()->removed_at);
        $this->assertDatabaseCount('council_consumers', 1);
        $this->getJson('/council/api/v1/members')->assertOk()->assertJsonCount(0, 'members')->assertJsonPath('former_members.0.address', $member->address);
        $this->getJson($api)->assertOk();
        $this->assertSame([], app(Census::class)->request());
        $this->assertDatabaseCount('lab_run_intents', 0);
        Http::assertNothingSent();
    }

    public function test_default_weight_one_conflict_still_blocks_and_closed_tally_is_not_rewritten(): void
    {
        $member = $this->member();
        config(['council.whitelist.agents' => ['clerk@synthetic']]);
        $ledger = Council::ledger();
        $change = $ledger->propose([
            'title' => 'Synthetic conflict control', 'summary' => 'Synthetic only.', 'approach' => 'Synthetic approach.',
            'classes' => ['declared-breaking'], 'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness']],
            'evidence' => [], 'links' => [], 'source' => 'agent_request', 'initiator' => 'clerk@synthetic',
            'pros' => 'pro', 'cons' => 'con',
        ]);
        $petition = Petition::query()->sole();
        $text = "COUNCIL-REPLY v1\n".json_encode([
            'change' => $change->ref, 'round' => 1, 'nonce' => $petition->nonce, 'digest' => $petition->round->approach_digest,
            'verdict' => 'conflict', 'impact' => [['app' => 'Synthetic app', 'paths' => [], 'severity' => 'blocker', 'note' => 'Synthetic blocker.']],
            'reason' => 'Synthetic only.',
        ], JSON_THROW_ON_ERROR);
        $this->assertSame('counted', app(Ingest::class)->receive($member->address, $text, 'synthetic-conflict')->status);
        $ledger->tallyChange($change);
        $this->assertSame('awaiting_revision', $change->fresh()->state);
        $round = $change->fresh()->currentRound();
        $tally = $round->tally;
        $this->assertSame(1, $tally['blocking_weight']);
        $this->assertSame(1, $tally['threshold']);
        $this->assertFalse($change->fresh()->blocking_below_threshold);
        $this->putJson('/council/api/v1/members/'.$member->address.'/weight', ['weight' => 0])->assertOk();
        $this->putJson('/council/api/v1/settings/blocking-threshold', ['threshold' => 2])->assertOk();
        $this->assertSame($tally, $round->fresh()->tally);
        $this->assertSame('awaiting_revision', $change->fresh()->state);
        Http::assertNothingSent();
    }

    private function member(): Consumer
    {
        return Consumer::query()->create([
            'address' => 'member@synthetic', 'workspace' => 'synthetic',
            'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness', 'constraint' => '*']],
            'watches' => [], 'internal' => false, 'whitelisted' => false, 'census_at' => now(),
        ]);
    }
}
