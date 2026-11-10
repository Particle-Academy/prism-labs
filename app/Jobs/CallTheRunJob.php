<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benchmarks\BenchmarkCommentator;
use App\Benchmarks\BenchmarkWork;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\SessionNotReady;
use App\Models\BenchmarkRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Ask the overseer for the next line of commentary on a live run.
 *
 * On the `commentary` queue, NOT `default`. The lane itself occupies `default`
 * for the whole length of a run, so a commentary job queued there would be
 * picked up after the thing it was meant to narrate had already finished.
 */
final class CallTheRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public ?string $paidWorkId = null;

    public int $sequence = 1;

    public function __construct(public readonly string $runId, ?string $paidWorkId = null, int $sequence = 1)
    {
        $this->paidWorkId = $paidWorkId;
        $this->sequence = $sequence;
        $this->onQueue('commentary');
    }

    /**
     * One call per run at a time. The Run Room asks on a timer and several
     * viewers may be watching the same run, so without this the overseer would
     * be asked to narrate the same events concurrently — paying twice for two
     * lines that then contradict each other about what just happened.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->runId))->dontRelease()];
    }

    /** How long to wait before calling the next batch of a live run. */
    private const CADENCE_SECONDS = 15;

    public function handle(BenchmarkCommentator $commentator): void
    {
        $run = BenchmarkRun::query()->with(['spec', 'lanes'])->find($this->runId);

        if (! $run instanceof BenchmarkRun) {
            return;
        }

        try {
            app(PaidWorkRegistry::class)->run($this->paidWorkId, 'benchmark.commentary', BenchmarkWork::commentaryKey($run->id, $this->sequence),
                BenchmarkWork::commentaryPayload($run->id, $this->sequence), fn () => $this->executeApproved($run, $commentator));
        } catch (PaidRunNotAuthorised|SessionNotReady) {
            // Refusal and duplicate delivery schedule nothing and spend nothing.
        }
    }

    private function executeApproved(BenchmarkRun $run, BenchmarkCommentator $commentator): void
    {
        BenchmarkWork::assertRun($run);
        // Reserve this delivery, including an empty feed, before it can create
        // a successor. This is delivery accounting, never provider invoicing.
        app(PaidRunContext::class)->requireWork('benchmark.commentary', BenchmarkWork::commentaryKey($run->id, $this->sequence),
            BenchmarkWork::commentaryPayload($run->id, $this->sequence));
        $commentator->call($run, $this->sequence);

        // The broadcast KEEPS ITSELF GOING while the run is live.
        //
        // This used to be driven by the Run Room's own poll, which meant the
        // commentary only advanced while somebody happened to be looking at
        // the page — close the tab and the run went unnarrated, and when the
        // site returned 502 the ticker simply stopped mid-run with no way to
        // tell that apart from a quiet stretch. A broadcast that only happens
        // when observed is not a record of the run.
        //
        // The chain ends by itself: a terminal run schedules nothing, so there
        // is no loop to stop and nothing to clean up if the worker dies.
        if (in_array($run->refresh()->status, ['queued', 'ready', 'running'], true)) {
            $work = BenchmarkWork::bindCommentary($run, $this->sequence + 1);
            self::dispatch($this->runId, $work->id, $this->sequence + 1)->delay(now()->addSeconds(self::CADENCE_SECONDS))->afterCommit();
        }
    }
}
