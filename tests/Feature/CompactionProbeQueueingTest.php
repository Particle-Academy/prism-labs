<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Lab\CompactionProbeController;
use App\Jobs\RunCompactionRecallProbe;
use App\Jobs\RunCompactionReservationProbe;
use App\Jobs\RunSummaryLossProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * No probe may run inside a request.
 *
 * The reservation probe did. It drives up to fourteen rounds of a live agent
 * loop — minutes — and this app is served single-threaded, so an inline run
 * froze the WHOLE Lab until it finished. The reported symptoms looked like four
 * separate bugs (a progress bar that vanished, a button stuck on "Running…", no
 * result, a chat panel that would not open) and were one blocked thread.
 *
 * These are cheap and they are the guard: anyone who "simplifies" a dispatch
 * back into a direct call fails here rather than in production.
 */
class CompactionProbeQueueingTest extends TestCase
{
    public function test_the_reservation_probe_is_queued_not_run_inline(): void
    {
        Queue::fake();

        $response = (new CompactionProbeController)->store(new Request(['reserved' => true]));

        Queue::assertPushed(RunCompactionReservationProbe::class);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_the_control_arm_is_queued_too(): void
    {
        Queue::fake();

        (new CompactionProbeController)->store(new Request(['reserved' => false]));

        Queue::assertPushed(RunCompactionReservationProbe::class);
    }

    public function test_every_other_probe_stays_queued(): void
    {
        Queue::fake();

        (new CompactionProbeController)->recall();
        (new CompactionProbeController)->summaryLoss();

        Queue::assertPushed(RunCompactionRecallProbe::class);
        Queue::assertPushed(RunSummaryLossProbe::class);
    }
}
