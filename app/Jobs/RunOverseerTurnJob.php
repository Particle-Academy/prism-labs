<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Lab\LabSession;
use App\Models\OverseerTurn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Run one Overseer turn OFF the web worker.
 *
 * ## Why this exists
 *
 * The turn used to run inside the web request. plabs is served by
 * `php artisan serve`, which is single-threaded on Windows — 629ms for one
 * request against 1726ms for three concurrent, strictly serialised — and an
 * Overseer turn can fan out to `conformance_<lang>`, which runs a whole suite,
 * or `ask_<lang>`, which calls another agent's model, up to `max_steps` times.
 *
 * So one broad question held the only worker for minutes. Every other request
 * got nothing, the browser showed "Unexpected end of JSON input" because the
 * response body was empty, and the site stayed wedged until someone restarted
 * it. That is not a timeout to tune: an agent that calls other agents does not
 * belong in a request at all.
 *
 * The Lab already knew this. `ScoreLaneJob` carries the same argument — a judge
 * running inline would extend the lane it is judging — and the chat did not get
 * the lesson until it took the whole site down.
 *
 * ## Its own queue, deliberately
 *
 * `default` holds the benchmark lanes for the length of a run, so a chat reply
 * queued there would wait behind every remaining lane. A person waiting on an
 * answer they asked for is the one thing on this queue that a human is watching
 * in real time.
 */
final class RunOverseerTurnJob implements ShouldQueue
{
    use Queueable;

    /**
     * ONE attempt. A turn is billable and can call other agents, whose calls are
     * billable in their own accounts — a retry spends that again on a question
     * the person has by then usually rephrased.
     */
    public int $tries = 1;

    /**
     * Generous, because the whole point is that this is allowed to be slow.
     * `coordinator.timeout` bounds a single provider call at 180s and
     * `max_steps` bounds the loop, so the worst case is a multiple of the two;
     * this sits above it so the job is killed by its own budget rather than
     * halfway through a step.
     */
    public int $timeout = 1800;

    public function __construct(public readonly string $turnId)
    {
        $this->onQueue('overseer');
    }

    /**
     * One turn at a time for the whole Overseer.
     *
     * Not per-turn: the thread is a single durable scope (`lab:agent`), so two
     * turns running at once would interleave writes to one conversation and
     * each would answer a history the other was still changing.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('overseer-turn'))->expireAfter(1800)];
    }

    public function handle(LabSession $sessions): void
    {
        $turn = OverseerTurn::query()->find($this->turnId);

        if (! $turn instanceof OverseerTurn || ! $turn->isPending()) {
            return;
        }

        $turn->forceFill(['status' => OverseerTurn::RUNNING, 'started_at' => now()])->save();

        try {
            $session = $sessions->resolveScope('lab:agent')
                ->usingProvider((string) config('team.coordinator.provider'))
                ->usingModel((string) config('team.coordinator.model'))
                ->usingMode('chat');

            $result = $session->send($turn->prompt);

            $turn->forceFill([
                'status' => OverseerTurn::ANSWERED,
                'answer' => $result->text(),
                'run_id' => $result->runId,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $failure) {
            report($failure);

            // The MESSAGE a person reads, not the exception. A chat panel is the
            // wrong place for a stack trace, and the useful part is that the
            // conversation survived — the thread is durable, so retrying costs
            // nothing that was already said.
            $turn->forceFill([
                'status' => OverseerTurn::FAILED,
                'error' => 'The Overseer could not complete that turn. Your conversation is preserved; ask again when the provider is available.',
                'finished_at' => now(),
            ])->save();
        }
    }

    /**
     * Called when the job itself dies — a timeout, a killed worker, a release
     * that runs out of attempts. Without this the row sits in `running` for
     * ever and the panel polls a turn that is never coming back, which is the
     * failure this whole change exists to stop, moved one layer down.
     */
    public function failed(?Throwable $failure): void
    {
        OverseerTurn::query()->where('id', $this->turnId)->whereIn('status', [OverseerTurn::QUEUED, OverseerTurn::RUNNING])->update([
            'status' => OverseerTurn::FAILED,
            'error' => 'The Overseer stopped before it finished that turn. Your conversation is preserved; ask again.',
            'finished_at' => now(),
        ]);
    }
}
