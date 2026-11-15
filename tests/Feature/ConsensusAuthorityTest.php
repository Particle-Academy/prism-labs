<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Consensus\ConsensusCoordinator;
use App\Http\Controllers\Lab\ConsensusController;
use App\Jobs\CollectConsensusJob;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\RunAuthority;
use App\Models\ConsensusRun;
use App\Models\LabPaidWork;
use App\Team\LanguageAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

final class ConsensusAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    private function admittedRun(): ConsensusRun
    {
        return $this->withApprovedLabRun('consensus.collect', ['question' => 'Owned question', 'evidence' => 'Owned brief'],
            fn () => app(ConsensusCoordinator::class)->begin('Owned question', ['brief' => 'Owned brief']));
    }

    private function assertNoAgentTraffic(): void
    {
        Http::assertNotSent(fn ($request) => ! str_starts_with($request->url(), 'https://api.github.com/'));
    }

    public function test_direct_collection_without_approval_cannot_create_a_run(): void
    {
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(ConsensusCoordinator::class)->collect('unapproved');
        } finally {
            $this->assertSame(0, ConsensusRun::query()->count());
        }
    }

    public function test_the_controller_only_dispatches_the_exact_persisted_work(): void
    {
        Queue::fake();
        $response = $this->withApprovedLabRun('consensus.collect', ['question' => 'Owned question', 'evidence' => 'Owned brief'],
            fn () => app(ConsensusController::class)->store(new Request(['question' => 'Owned question', 'evidence' => 'Owned brief']), app(ConsensusCoordinator::class)));
        $run = ConsensusRun::query()->sole();
        $this->assertSame(302, $response->getStatusCode());
        Queue::assertPushedOn('overseer', CollectConsensusJob::class);
        Queue::assertPushed(CollectConsensusJob::class, fn ($job) => $job->runId === $run->id
            && $job->paidWorkId === $run->paid_work_id && $job->evidence === ['brief' => 'Owned brief']);
        $this->assertNull(LabPaidWork::query()->findOrFail($run->paid_work_id)->claimed_at);
        $this->assertNoAgentTraffic();
    }

    public function test_a_worker_without_a_login_collects_fixed_unavailable_results_once_without_agent_calls(): void
    {
        $run = $this->admittedRun();
        $this->assertGuest('web');
        $this->beginApprovedJob('overseer');
        $job = new CollectConsensusJob($run->id, ['brief' => 'Owned brief'], $run->paid_work_id);
        $job->handle(app(ConsensusCoordinator::class));
        $this->assertSame('awaiting_review', $run->refresh()->status);
        $this->assertSame(2, $run->responses()->count());
        foreach ($run->responses as $response) {
            $this->assertSame('unavailable', $response->status);
            $this->assertSame(LanguageAgent::PAID_AUTHORITY_UNAVAILABLE, $response->failure_reason);
            $this->assertNull($response->answer);
        }
        $this->assertNotNull(LabPaidWork::query()->findOrFail($run->paid_work_id)->claimed_at);
        $job->handle(app(ConsensusCoordinator::class));
        $this->assertSame(2, $run->responses()->count());
        $this->assertNoAgentTraffic();
    }

    public function test_a_changed_question_refuses_before_any_response(): void
    {
        $run = $this->admittedRun();
        $run->forceFill(['question' => 'changed'])->save();
        $this->beginApprovedJob('overseer');
        (new CollectConsensusJob($run->id, ['brief' => 'Owned brief'], $run->paid_work_id))->handle(app(ConsensusCoordinator::class));
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        $this->assertNull(LabPaidWork::query()->findOrFail($run->paid_work_id)->claimed_at);
        $this->assertNoAgentTraffic();
    }

    public function test_a_changed_queued_brief_refuses_before_any_response(): void
    {
        $run = $this->admittedRun();
        $this->beginApprovedJob('overseer');
        (new CollectConsensusJob($run->id, ['brief' => 'changed'], $run->paid_work_id))->handle(app(ConsensusCoordinator::class));
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        $this->assertNull(LabPaidWork::query()->findOrFail($run->paid_work_id)->claimed_at);
        $this->assertNoAgentTraffic();
    }

    public function test_legacy_collection_is_abandoned_without_transport(): void
    {
        $this->prepareLabAuthority();
        $run = ConsensusRun::query()->create(['question' => 'legacy', 'evidence_digest' => str_repeat('a', 64), 'status' => 'collecting']);
        (new CollectConsensusJob($run->id, []))->handle(app(ConsensusCoordinator::class));
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        $this->assertNoAgentTraffic();
    }

    public function test_a_revoked_run_stops_after_dispatch_without_an_owner_session(): void
    {
        $run = $this->admittedRun();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($run->approved_run_id);
        auth('web')->logout();
        $this->beginApprovedJob('overseer');
        (new CollectConsensusJob($run->id, ['brief' => 'Owned brief'], $run->paid_work_id))->handle(app(ConsensusCoordinator::class));
        $this->assertSame('abandoned', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        $this->assertNoAgentTraffic();
    }

    public function test_a_duplicate_cannot_abandon_an_already_claimed_collection(): void
    {
        $run = $this->admittedRun();
        LabPaidWork::query()->whereKey($run->paid_work_id)->update(['claimed_at' => now()]);
        $this->beginApprovedJob('overseer');
        (new CollectConsensusJob($run->id, ['brief' => 'Owned brief'], $run->paid_work_id))->handle(app(ConsensusCoordinator::class));
        $this->assertSame('collecting', $run->refresh()->status);
        $this->assertSame(0, $run->responses()->count());
        $this->assertNoAgentTraffic();
    }
}
