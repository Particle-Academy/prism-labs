<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\CompactionRecallProbe;
use App\Benchmarks\CompactionReservationProbe;
use App\Benchmarks\SummaryLossProbe;
use App\Jobs\RunCompactionRecallProbe;
use App\Jobs\RunCompactionReservationProbe;
use App\Jobs\RunSummaryLossProbe;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunAuthority;
use App\Lab\SessionNotReady;
use App\Models\CompactionProbeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

/** SDK fakes prove dispatch authority, never a live compaction verdict. */
final class CompactionAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    public static function probes(): array
    {
        return [
            'reservation' => ['compaction.reservation', 'benchmarks.compaction-probe', RunCompactionReservationProbe::class, CompactionReservationProbe::class],
            'recall' => ['compaction.recall', 'benchmarks.compaction-recall', RunCompactionRecallProbe::class, CompactionRecallProbe::class],
            'summary-loss' => ['compaction.summary-loss', 'benchmarks.summary-loss', RunSummaryLossProbe::class, SummaryLossProbe::class],
        ];
    }

    private function admittedProbe(string $kind, string $action, string $jobClass): array
    {
        return $this->withApprovedLabRun($action, [], function () use ($kind, $action, $jobClass): array {
            $intent = app(PaidRunContext::class)->authorizedRun([$action]);
            $payload = $kind === 'compaction.reservation' ? ['reserved' => true, 'trigger' => 5000, 'keep' => 3] : [];
            $work = app(PaidWorkRegistry::class)->bind($kind, $intent->id, $payload);

            return [new $jobClass(approvedRunId: $intent->id, paidWorkId: $work->id), $work];
        });
    }

    #[DataProvider('probes')]
    public function test_a_legacy_probe_job_refuses_before_the_sdk(string $kind, string $action, string $jobClass, string $probeClass): void
    {
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            (new $jobClass)->handle(app($probeClass));
        } finally {
            $fake->assertCallCount(0);
            $this->assertSame(0, CompactionProbeRun::query()->count());
        }
    }

    #[DataProvider('probes')]
    public function test_an_admitted_probe_runs_once_in_the_native_queue_without_a_login(string $kind, string $action, string $jobClass, string $probeClass): void
    {
        $fake = Prism::fake([]);
        [$job, $work] = $this->admittedProbe($kind, $action, $jobClass);
        $this->assertGuest('web');
        $this->beginApprovedJob('default');
        $job->handle(app($probeClass));
        $fake->assertRequest(fn ($requests) => $this->assertNotEmpty($requests, 'The authorized arm must reach the fake SDK.'));
        $this->assertSame(1, CompactionProbeRun::query()->count());
        $this->assertNotNull($work->refresh()->claimed_at);
        $count = 0;
        $fake->assertRequest(function ($requests) use (&$count): void {
            $count = count($requests);
        });

        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $job->handle(app($probeClass));
        } finally {
            $fake->assertCallCount($count);
            $this->assertSame(1, CompactionProbeRun::query()->count());
        }
    }

    #[DataProvider('probes')]
    public function test_revocation_after_dispatch_stops_each_probe(string $kind, string $action, string $jobClass, string $probeClass): void
    {
        $fake = Prism::fake([]);
        [$job, $work] = $this->admittedProbe($kind, $action, $jobClass);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        app(RunAuthority::class)->revoke($work->intent_id);
        auth('web')->logout();
        $this->beginApprovedJob('default');

        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $job->handle(app($probeClass));
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull($work->refresh()->claimed_at);
        }
    }

    #[DataProvider('probes')]
    public function test_an_idle_worker_label_cannot_authorise_a_direct_job_call(string $kind, string $action, string $jobClass, string $probeClass): void
    {
        $fake = Prism::fake([]);
        [$job, $work] = $this->admittedProbe($kind, $action, $jobClass);
        $this->expectException(SessionNotReady::class);
        try {
            $job->handle(app($probeClass));
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull($work->refresh()->claimed_at);
        }
    }

    public function test_changed_reservation_options_do_not_spend_the_original_authority(): void
    {
        $fake = Prism::fake([]);
        [$job, $work] = $this->admittedProbe('compaction.reservation', 'benchmarks.compaction-probe', RunCompactionReservationProbe::class);
        $changed = new RunCompactionReservationProbe(reserved: false, approvedRunId: $work->intent_id, paidWorkId: $work->id);
        $this->beginApprovedJob('default');
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $changed->handle(app(CompactionReservationProbe::class));
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull($work->refresh()->claimed_at);
        }
    }

    public function test_a_probe_reference_cannot_be_substituted_for_another_kind(): void
    {
        $fake = Prism::fake([]);
        [$job, $work] = $this->admittedProbe('compaction.recall', 'benchmarks.compaction-recall', RunCompactionRecallProbe::class);
        $changed = new RunSummaryLossProbe($work->intent_id, $work->id);
        $this->beginApprovedJob('default');
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $changed->handle(app(SummaryLossProbe::class));
        } finally {
            $fake->assertCallCount(0);
            $this->assertNull($work->refresh()->claimed_at);
        }
    }

    #[DataProvider('probes')]
    public function test_calling_the_probe_service_directly_without_authority_cannot_reach_the_sdk(string $kind, string $action, string $jobClass, string $probeClass): void
    {
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app($probeClass)->run();
        } finally {
            $fake->assertCallCount(0);
        }
    }

    #[DataProvider('probes')]
    public function test_an_approved_action_cannot_select_an_unreviewed_model(string $kind, string $action, string $jobClass, string $probeClass): void
    {
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withApprovedLabRun($action, [], fn () => app($probeClass)->run(model: 'unreviewed-model'));
        } finally {
            $fake->assertCallCount(0);
        }
    }

    public function test_the_reserved_http_probe_cannot_silently_select_the_unreserved_control(): void
    {
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withApprovedLabRun('benchmarks.compaction-probe', [], fn () => app(CompactionReservationProbe::class)->run(reserve: false));
        } finally {
            $fake->assertCallCount(0);
        }
    }

    public function test_the_cli_can_run_both_explicitly_approved_arms_without_self_approval(): void
    {
        $fake = Prism::fake([]);
        [$intent] = $this->approvedLabRun('cli.compaction-probe', ['model' => 'claude-sonnet-5', 'trigger' => 5000,
            'keep' => 3, 'steps' => 24, 'control' => true]);
        $this->assertGuest('web');
        // The model fake never exercises the reserved tool, so the honest
        // probe verdict is inconclusive and its native command exits nonzero.
        $this->artisan('compaction:probe', ['--control' => true, '--approved-run' => $intent->id])->assertExitCode(1);
        $fake->assertCallCount(2);
        $this->assertSame(2, CompactionProbeRun::query()->count());
    }
}
