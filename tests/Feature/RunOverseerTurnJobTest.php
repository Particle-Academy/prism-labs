<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\AgentConversationController;
use App\Jobs\RunOverseerTurnJob;
use App\Lab\LabSession;
use App\Lab\PaidRunContext;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Models\LabPaidWork;
use App\Models\OverseerTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use RuntimeException;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

/**
 * The Overseer turn, run off the web worker.
 *
 * It used to run inside the request, on a site served single-threaded, and one
 * broad question held the only worker for minutes — an empty body in the
 * browser and a wedged site until someone restarted it. These pin the parts of
 * the replacement that fail silently when they break, which is every part of it:
 * a turn that never finishes looks exactly like a turn that is still thinking.
 */
class RunOverseerTurnJobTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    private function pendingTurn(string $status = OverseerTurn::QUEUED): OverseerTurn
    {
        return OverseerTurn::query()->create(['status' => $status, 'prompt' => 'where are we at with everything']);
    }

    private function approvedTurn(string $prompt = 'where are we at with everything'): OverseerTurn
    {
        return $this->withApprovedLabRun('agent.send', ['message' => $prompt], function () use ($prompt): OverseerTurn {
            $intent = app(PaidRunContext::class)->authorizedRun(['agent.send']);
            $turn = OverseerTurn::query()->create(['status' => OverseerTurn::QUEUED, 'prompt' => $prompt, 'approved_run_id' => $intent->id]);
            $work = app(PaidWorkRegistry::class)->bind('overseer.turn', $turn->id,
                ['prompt' => $turn->prompt, 'approved_run_id' => $turn->approved_run_id]);
            $turn->forceFill(['paid_work_id' => $work->id])->save();

            return $turn;
        });
    }

    public function test_a_dead_job_marks_its_turn_failed_rather_than_leaving_it_pending(): void
    {
        // THE ONE THAT MATTERS. A worker killed mid-turn, a timeout, a job that
        // runs out of attempts — without failed() the row stays `running` for
        // ever and the panel polls a turn that is never coming back. That is the
        // outage this whole change replaced, moved one layer down, and it would
        // be invisible: a stuck turn and a slow one look the same from outside.
        $turn = $this->pendingTurn(OverseerTurn::RUNNING);

        (new RunOverseerTurnJob($turn->id))->failed(new RuntimeException('worker killed'));

        $turn->refresh();
        $this->assertSame(OverseerTurn::FAILED, $turn->status);
        $this->assertFalse($turn->isPending());
        $this->assertNotNull($turn->finished_at);
    }

    public function test_the_failure_a_person_sees_is_a_sentence_not_the_exception(): void
    {
        // The exception is logged; the chat panel gets words. A stack trace in
        // the panel is noise that also leaks more than it should, and the useful
        // part for a person is that the conversation survived.
        $turn = $this->pendingTurn();

        (new RunOverseerTurnJob($turn->id))->failed(new RuntimeException('SQLSTATE[HY000] internal detail /var/www'));

        $error = (string) $turn->refresh()->error;
        $this->assertStringContainsString('conversation is preserved', $error);
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringNotContainsString('/var/www', $error);
    }

    public function test_a_late_failure_does_not_clobber_an_answer_that_already_landed(): void
    {
        // failed() can arrive after the turn completed — a job marked failed by
        // the queue after its work was written. Overwriting an ANSWERED row
        // would throw away a real answer the person is about to read.
        $turn = $this->pendingTurn();
        $turn->forceFill(['status' => OverseerTurn::ANSWERED, 'answer' => 'Sixty words.'])->save();

        (new RunOverseerTurnJob($turn->id))->failed(new RuntimeException('late'));

        $turn->refresh();
        $this->assertSame(OverseerTurn::ANSWERED, $turn->status);
        $this->assertSame('Sixty words.', $turn->answer);
    }

    public function test_a_turn_that_is_already_settled_spends_no_model_call(): void
    {
        // A redelivery for a finished turn must be a no-op. The CALL COUNT is the
        // check. (An empty Prism fake does not throw on a call — it answers with
        // a default — so a comment saying the fake would catch a stray call was
        // wrong, and was corrected when a test here relied on it and failed.)
        $fake = Prism::fake([]);
        $turn = $this->pendingTurn();
        $turn->forceFill(['status' => OverseerTurn::ANSWERED, 'answer' => 'done'])->save();

        (new RunOverseerTurnJob($turn->id))->handle(app(LabSession::class));

        $fake->assertCallCount(0);
        $this->assertSame('done', $turn->refresh()->answer);
    }

    public function test_a_provider_failure_inside_the_turn_lands_as_failed(): void
    {
        // The in-band failure, distinct from the job dying. The turn catches it,
        // reports it, and writes a failed row the panel can stop polling on.
        //
        // Driven through the REAL provider handler with a real HTTP 500, not a
        // Prism fake. The first draft used an empty fake on the assumption it
        // throws; it does not — it answers with a default — and the turn landed
        // `answered`, which is how this test caught its own premise.
        $turn = $this->approvedTurn();
        Http::fake(['https://api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'down']], 500)]);
        $this->beginApprovedJob('overseer');
        (new RunOverseerTurnJob($turn->id, $turn->paid_work_id))->handle(app(LabSession::class));

        $this->assertSame(OverseerTurn::FAILED, $turn->refresh()->status);
        $this->assertStringContainsString('provider is available', $turn->error);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.anthropic.com/'));
        $this->assertNotNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
    }

    public function test_the_request_only_dispatches_and_never_runs_the_turn(): void
    {
        // The vacuity guard for everything above: if the controller ran the turn
        // inline again, every test here could still pass while the site went
        // back to wedging. So the request is asserted to DISPATCH, and the model
        // call count is asserted to be zero — a turn that ran inline would have
        // made one.
        Queue::fake();
        $fake = Prism::fake([]);

        $response = $this->withApprovedLabRun('agent.send', ['message' => 'hello'], fn () => app(AgentConversationController::class)->send(Request::create('/lab/agent', 'POST', ['message' => 'hello'])));

        $this->assertSame(202, $response->getStatusCode());
        Queue::assertPushedOn('overseer', RunOverseerTurnJob::class);
        $fake->assertCallCount(0);
        $turn = OverseerTurn::query()->sole();
        Queue::assertPushed(RunOverseerTurnJob::class, fn ($job) => $job->turnId === $turn->id && $job->paidWorkId === $turn->paid_work_id);
        $this->assertSame($turn->approved_run_id, LabPaidWork::query()->findOrFail($turn->paid_work_id)->intent_id);
    }

    public function test_a_legacy_job_without_authority_refuses_before_a_builder(): void
    {
        $fake = Prism::fake([]);
        $turn = $this->pendingTurn();
        (new RunOverseerTurnJob($turn->id))->handle(app(LabSession::class));

        $fake->assertCallCount(0);
        $this->assertSame(OverseerTurn::FAILED, $turn->refresh()->status);
        $this->assertStringContainsString('No turn was started', $turn->error);
    }

    public function test_a_queued_prompt_changed_after_approval_cannot_spend(): void
    {
        $fake = Prism::fake([]);
        $turn = $this->approvedTurn();
        $turn->forceFill(['prompt' => 'unapproved replacement'])->save();
        $this->beginApprovedJob('overseer');
        (new RunOverseerTurnJob($turn->id, $turn->paid_work_id))->handle(app(LabSession::class));

        $fake->assertCallCount(0);
        $this->assertSame(OverseerTurn::FAILED, $turn->refresh()->status);
        $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
    }

    public function test_a_substituted_reference_cannot_authorise_another_turn(): void
    {
        $fake = Prism::fake([]);
        $first = $this->approvedTurn('first');
        $second = $this->approvedTurn('second');
        $this->beginApprovedJob('overseer');
        (new RunOverseerTurnJob($second->id, $first->paid_work_id))->handle(app(LabSession::class));

        $fake->assertCallCount(0);
        $this->assertSame(OverseerTurn::FAILED, $second->refresh()->status);
        $this->assertNull(LabPaidWork::query()->findOrFail($first->paid_work_id)->claimed_at);
        $this->assertNull(LabPaidWork::query()->findOrFail($second->paid_work_id)->claimed_at);
    }

    public function test_revocation_after_dispatch_stops_the_actual_job(): void
    {
        $fake = Prism::fake([]);
        $turn = $this->approvedTurn();
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($turn->approved_run_id);
        auth('web')->logout();
        $this->beginApprovedJob('overseer');
        (new RunOverseerTurnJob($turn->id, $turn->paid_work_id))->handle(app(LabSession::class));

        $fake->assertCallCount(0);
        $this->assertSame(OverseerTurn::FAILED, $turn->refresh()->status);
        $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
    }

    public function test_an_approved_turn_runs_once_without_a_web_login_and_a_late_delivery_preserves_the_answer(): void
    {
        $fake = Prism::fake([]);
        $turn = $this->approvedTurn();
        $this->assertGuest('web');
        $this->beginApprovedJob('overseer');
        $job = new RunOverseerTurnJob($turn->id, $turn->paid_work_id);
        $job->handle(app(LabSession::class));
        $fake->assertCallCount(1);
        $this->assertSame(OverseerTurn::ANSWERED, $turn->refresh()->status);
        $answer = $turn->answer;

        $job->handle(app(LabSession::class));
        $fake->assertCallCount(1);
        $this->assertSame(OverseerTurn::ANSWERED, $turn->refresh()->status);
        $this->assertSame($answer, $turn->answer);
    }

    public function test_a_duplicate_delivery_cannot_restart_an_active_turn(): void
    {
        $fake = Prism::fake([]);
        $turn = $this->approvedTurn();
        $turn->forceFill(['status' => OverseerTurn::RUNNING])->save();
        (new RunOverseerTurnJob($turn->id))->handle(app(LabSession::class));
        $fake->assertCallCount(0);
        $this->assertSame(OverseerTurn::RUNNING, $turn->refresh()->status);
    }
}
