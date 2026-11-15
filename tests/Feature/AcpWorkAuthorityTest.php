<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Acp\AcpClient;
use App\Acp\AcpWork;
use App\Acp\ApprovedPrompt;
use App\Http\Controllers\Lab\AcpController;
use App\Jobs\RunAcpTurnJob;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\AcpTurn;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ApprovesLabRuns;
use Tests\Concerns\NativeAcpStopWorker;
use Tests\TestCase;

/** Queued ACP admission before any native process, credentials or provider. */
final class AcpWorkAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    public function test_direct_controller_requires_authority_even_without_the_route(): void
    {
        Queue::fake();
        $this->refuses(fn () => $this->send(['prompt' => 'owned ACP prompt']));
        $this->assertSame(0, AcpTurn::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_producer_binds_effective_model_and_dispatches_one_existing_reference(): void
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $this->assertSame('claude', $turn->provider);
        $this->assertSame('haiku', $turn->model);
        $intent = LabRunIntent::query()->findOrFail($turn->approved_run_id);
        $work = LabPaidWork::query()->findOrFail($turn->paid_work_id);
        $this->assertSame('running', $intent->status);
        $this->assertSame('acp.turn', $work->kind);
        $this->assertSame($turn->id, $work->work_key);
        $this->assertSame($intent->id, $work->intent_id);
        $this->assertSame('haiku', $work->payload['model']);
        $this->assertNull($work->claimed_at);
        $this->assertNull($intent->acp_host_claimed_at);
        $this->assertNotNull($this->dispatchedJob);
        $this->assertSame($work->id, $this->dispatchedJob->paidWorkId);
        $this->assertSame('overseer', $this->dispatchedJob->queue);
        $this->assertGuest('web');
    }

    public function test_direct_changed_request_and_second_root_are_refused_without_another_dispatch(): void
    {
        Queue::fake();
        $this->withApprovedLabRun('acp.send', ['prompt' => 'owned ACP prompt'], function (): void {
            $this->refuses(fn () => $this->send(['prompt' => 'different']));
            $this->refuses(fn () => $this->send(['prompt' => 'owned ACP prompt', 'model' => 'different']));
            $this->assertSame(202, $this->send(['prompt' => 'owned ACP prompt'])->getStatusCode());
            $this->refuses(fn () => $this->send(['prompt' => 'owned ACP prompt']));
        });
        $this->assertSame(1, AcpTurn::query()->count());
        $this->assertSame(1, LabPaidWork::query()->count());
        Queue::assertPushed(RunAcpTurnJob::class, 1);
    }

    public static function changedWork(): array
    {
        return [
            'missing argument' => ['argument'], 'missing row ref' => ['row-ref'],
            'prompt' => ['prompt'], 'model' => ['model'], 'provider' => ['provider'],
            'resume' => ['resume_session_id'], 'intent' => ['approved_run_id'],
            'payload' => ['payload'], 'digest' => ['digest'], 'revocation' => ['revoked'],
            'duplicate claim' => ['claimed'], 'expiry' => ['expired'],
        ];
    }

    #[DataProvider('changedWork')]
    public function test_missing_changed_or_spent_work_never_starts_a_host(string $change): void
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $work = LabPaidWork::query()->findOrFail($turn->paid_work_id);
        $id = $work->id;
        match ($change) {
            'argument' => $id = null,
            'row-ref' => $turn->forceFill(['paid_work_id' => null])->save(),
            'prompt', 'model', 'provider', 'resume_session_id' => $turn->forceFill([$change => 'changed'])->save(),
            'approved_run_id' => $turn->forceFill(['approved_run_id' => null])->save(),
            'payload' => $work->forceFill(['payload' => ['prompt' => 'changed']])->save(),
            'digest' => $work->forceFill(['payload_digest' => str_repeat('0', 64)])->save(),
            'revoked' => LabRunIntent::query()->whereKey($turn->approved_run_id)->update(['status' => 'revoked', 'revoked_at' => now()]),
            'claimed' => $work->forceFill(['claimed_at' => now()])->save(),
            'expired' => LabRunIntent::query()->whereKey($turn->approved_run_id)->update(['expires_at' => now()->subSecond()]),
        };
        $this->beginApprovedJob('overseer');
        (new RunAcpTurnJob($turn->id, $id))->handle();
        $this->assertSame(AcpTurn::FAILED, $turn->refresh()->status);
        $this->assertNull($turn->process);
        $this->assertNull($turn->handshake);
        $this->assertNull($turn->started_at);
        $this->assertSame('The approved run authority or current session readiness is unavailable. No ACP host was started.', $turn->error);
        $this->assertNull(LabRunIntent::query()->findOrFail($work->intent_id)->acp_host_claimed_at);
        if ($change !== 'claimed') {
            $this->assertNull($work->fresh()->claimed_at);
        }
        $this->assertGuest('web');
    }

    public function test_an_already_running_turn_is_untouched_by_late_or_legacy_delivery(): void
    {
        $turn = AcpTurn::query()->create(['status' => AcpTurn::RUNNING, 'prompt' => 'owned pending turn']);
        (new RunAcpTurnJob($turn->id))->handle();
        $this->assertSame(AcpTurn::RUNNING, $turn->refresh()->status);
        $this->assertNull($turn->error);
        $this->assertNull($turn->process);
    }

    public function test_a_valid_queued_reference_cannot_execute_outside_a_native_worker(): void
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $this->dispatchedJob->handle();
        $this->assertSame(AcpTurn::FAILED, $turn->refresh()->status);
        $this->assertNull($turn->process);
        $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
    }

    public function test_direct_client_construction_cannot_bypass_the_job_or_reuse_a_valid_root(): void
    {
        $this->refuses(fn () => new AcpClient(fn () => null, fn () => null));
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $turn->forceFill(['status' => AcpTurn::RUNNING])->save();
        $this->refuses(fn () => new AcpClient(fn () => null, fn () => null, turnId: $turn->id));
        $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
        $this->assertNull(LabRunIntent::query()->findOrFail($turn->approved_run_id)->acp_host_claimed_at);
    }

    public function test_a_valid_http_root_context_cannot_be_promoted_to_a_native_client(): void
    {
        Queue::fake();
        $this->withApprovedLabRun('acp.send', ['prompt' => 'owned ACP prompt'], function (): void {
            $response = $this->send(['prompt' => 'owned ACP prompt']);
            $turn = AcpTurn::query()->findOrFail($response->getData(true)['id']);
            $turn->forceFill(['status' => AcpTurn::RUNNING])->save();
            $this->refuses(fn () => new AcpClient(fn () => null, fn () => null, turnId: $turn->id));
            $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
        });
    }

    public function test_actual_owner_go_route_dispatches_exactly_one_bound_acp_turn(): void
    {
        [$intent] = $this->approvedLabRun('acp.send', ['prompt' => 'owned ACP prompt']);
        $owner = User::query()->findOrFail($intent->approved_by);
        $this->actingAs($owner, 'web');
        $this->withSession(['_token' => 'owned-acp-csrf'])->withHeader('X-CSRF-TOKEN', 'owned-acp-csrf');
        Queue::fake();
        $input = ['prompt' => 'owned ACP prompt', 'approved_run_id' => $intent->id];
        $this->postJson('/lab/acp', $input)->assertAccepted();
        $turn = AcpTurn::query()->sole();
        $this->assertSame($intent->id, $turn->approved_run_id);
        $this->assertSame('haiku', $turn->model);
        $this->assertSame($turn->id, LabPaidWork::query()->findOrFail($turn->paid_work_id)->work_key);
        Queue::assertPushed(RunAcpTurnJob::class, fn (RunAcpTurnJob $job) => $job->turnId === $turn->id && $job->paidWorkId === $turn->paid_work_id);
        $this->postJson('/lab/acp', $input)->assertForbidden();
        $this->assertSame(1, AcpTurn::query()->count());
        $this->assertSame(1, LabPaidWork::query()->count());
        Queue::assertPushed(RunAcpTurnJob::class, 1);
    }

    public function test_a_queued_root_cannot_bypass_its_native_worker_by_starting_a_host_directly(): void
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $this->refuses(fn () => $this->acquireHost($turn));
        $this->assertNull(LabRunIntent::query()->findOrFail($turn->approved_run_id)->acp_host_claimed_at);
        $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
    }

    public function test_a_running_label_without_a_real_work_claim_cannot_start_a_host(): void
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $turn->forceFill(['status' => AcpTurn::RUNNING])->save();
        $this->refuses(fn () => $this->acquireHost($turn));
        $this->assertNull(LabRunIntent::query()->findOrFail($turn->approved_run_id)->acp_host_claimed_at);
        $this->assertNull(LabPaidWork::query()->findOrFail($turn->paid_work_id)->claimed_at);
    }

    public function test_a_dead_worker_finishes_only_its_own_bound_pending_turn_without_exposing_its_exception(): void
    {
        $turn = $this->claimedTurn();
        $failure = new \RuntimeException('synthetic-private-db-password');
        (new RunAcpTurnJob($turn->id))->failed($failure);
        $this->assertSame(AcpTurn::RUNNING, $turn->refresh()->status);
        (new RunAcpTurnJob($turn->id, 'not-the-bound-work'))->failed($failure);
        $this->assertSame(AcpTurn::RUNNING, $turn->refresh()->status);
        $this->dispatchedJob->failed($failure);
        $this->assertSame(AcpTurn::FAILED, $turn->refresh()->status);
        $this->assertNotNull($turn->finished_at);
        $this->assertStringNotContainsString('synthetic-private', $turn->error);
        $this->assertStringContainsString('new approved turn', $turn->error);
    }

    public function test_late_worker_failure_preserves_a_completed_native_result(): void
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $turn->forceFill(['status' => AcpTurn::FINISHED, 'stop_reason' => 'end_turn'])->save();
        $this->dispatchedJob->failed(new \RuntimeException('synthetic-private-error'));
        $this->assertSame(AcpTurn::FINISHED, $turn->refresh()->status);
        $this->assertSame('end_turn', $turn->stop_reason);
        $this->assertNull($turn->error);
    }

    public function test_free_native_worker_cleanup_refuses_a_forged_fixture_label_on_another_database(): void
    {
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        $worker = app('queue.worker');
        $this->assertFalse($worker->shouldQuit);
        config(['testing-native-acp.evidence_directory' => str_replace('\\', '/', dirname(base_path(), 3))
            .'/.ai/evidence/g246-foundations/approval-foundation/owner6762/resume-owner6853/acp-normal-host-v999/claude']);
        try {
            (new NativeAcpStopWorker)->handle();
            $this->fail('The cleanup job accepted a different database.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Refuse to stop a non-fixture worker.', $failure->getMessage());
        }
        $this->assertFalse($worker->shouldQuit);
    }

    public function test_claimed_native_parent_allows_one_host_and_one_matching_prompt(): void
    {
        $turn = $this->claimedTurn();
        $host = $this->acquireHost($turn);
        $this->refuses(fn () => $this->acquireHost($turn));
        $host->claimSession(['cwd' => config('acp.workspace'), 'mcpServers' => []]);
        $host->claimPrompt('owned ACP prompt');
        $this->refuses(fn () => $host->claimPrompt('owned ACP prompt'));
        $intent = LabRunIntent::query()->findOrFail($turn->approved_run_id);
        $this->assertNotNull($intent->acp_host_claimed_at);
        $this->assertNotNull($intent->acp_prompt_claimed_at);
        $this->assertGuest('web');
    }

    public static function changedHostParent(): array
    {
        return ['prompt' => ['prompt'], 'lost intent' => ['approved_run_id'],
            'lost work' => ['paid_work_id'], 'deleted row' => ['deleted'], 'finished' => ['status']];
    }

    #[DataProvider('changedHostParent')]
    public function test_a_host_rechecks_its_actual_claimed_parent_before_the_native_prompt(string $change): void
    {
        $turn = $this->claimedTurn();
        $intentId = $turn->approved_run_id;
        $host = $this->acquireHost($turn);
        $host->claimSession(['cwd' => config('acp.workspace'), 'mcpServers' => []]);
        if ($change === 'deleted') {
            $turn->delete();
        } else {
            $turn->forceFill([$change => match ($change) {
                'prompt' => 'changed', 'status' => AcpTurn::FINISHED, default => null,
            }])->save();
        }
        $this->refuses(fn () => $host->claimPrompt('owned ACP prompt'));
        $this->assertNull(LabRunIntent::query()->findOrFail($intentId)->acp_prompt_claimed_at);
    }

    public function test_a_changed_cli_binary_or_arguments_invalidates_the_approved_scope(): void
    {
        $turn = $this->claimedTurn();
        config(['acp.claude.binary_args' => ['changed.php']]);
        $this->refuses(fn () => $this->acquireHost($turn));
        $this->assertNull(LabRunIntent::query()->findOrFail($turn->approved_run_id)->acp_host_claimed_at);
    }

    private function acquireHost(AcpTurn $turn): ApprovedPrompt
    {
        return ApprovedPrompt::acquire($turn->approved_run_id, $turn->provider, app(RunAuthority::class), app(RunScopeFactory::class));
    }

    private function claimedTurn(): AcpTurn
    {
        $turn = $this->approvedTurn(['prompt' => 'owned ACP prompt']);
        $this->beginApprovedJob('overseer');
        app(PaidWorkRegistry::class)->run($turn->paid_work_id, 'acp.turn', $turn->id, AcpWork::payload($turn), function () use ($turn): void {
            app(PaidRunContext::class)->requireWork('acp.turn', $turn->id, AcpWork::payload($turn));
            $turn->forceFill(['status' => AcpTurn::RUNNING])->save();
        });
        // Host mode uses ordinary idle-worker preflight, never a fake worker
        // execution flag. Ending this event leaves genuine idle native evidence.
        Event::dispatch(new Looping('database', 'default,scoring', new WorkerOptions(timeout: 1800)));

        return $turn;
    }

    private ?RunAcpTurnJob $dispatchedJob = null;

    private function approvedTurn(array $input): AcpTurn
    {
        $manager = app('queue');
        Queue::fake();
        try {
            $turn = $this->withApprovedLabRun('acp.send', $input, function () use ($input): AcpTurn {
                $response = $this->send($input);
                $this->assertSame(202, $response->getStatusCode());

                return AcpTurn::query()->findOrFail($response->getData(true)['id']);
            });
            Queue::assertPushed(RunAcpTurnJob::class, 1);
            $this->dispatchedJob = Queue::pushed(RunAcpTurnJob::class)->first();

            return $turn;
        } finally {
            Queue::swap($manager);
        }
    }

    private function send(array $input): JsonResponse
    {
        return app(AcpController::class)->send(Request::create('/lab/acp', 'POST', $input));
    }

    private function refuses(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Missing or changed authority was accepted.');
        } catch (PaidRunNotAuthorised) {
            $this->addToAssertionCount(1);
        }
    }
}
