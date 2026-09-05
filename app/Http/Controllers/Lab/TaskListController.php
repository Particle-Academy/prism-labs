<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use App\Tasks\TaskAgentProbe;
use App\Tasks\TaskListProbe;
use App\Tasks\TaskProbeLearningRecorder;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Agent task lists, dogfooded.
 *
 * `prism-harness` shipped task lists in v0.3.0 across three languages and no
 * real application had used them. This surface is the first one that does —
 * and it asks the SECURITY properties rather than the happy path, because a
 * screen that seeds two tasks and claims them would have been green on every
 * version of the package including the ones with the hole in.
 *
 * Two lanes, deliberately separate:
 *
 *  - the property board is in-process, deterministic and free, and runs on
 *    demand;
 *  - the live agent lane calls a real provider, costs real money, and is a
 *    button.
 *
 * NEITHER RUNS ON PAGE LOAD, for the reason the team board gives: a probe that
 * runs while the page paints holds the whole page behind its slowest step, and
 * one that spends money on a page load spends it on every refresh.
 */
final class TaskListController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Lab/Tasks', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'model' => (string) config('team.tasks.model'),
            'provider' => (string) config('team.tasks.provider'),
        ]);
    }

    /**
     * The property board. In-process, frozen clock, no provider.
     */
    public function probe(TaskListProbe $probe, TaskProbeLearningRecorder $recorder): JsonResponse
    {
        $report = ['lists' => []];

        try {
            $report = $probe->run();
        } catch (Throwable $failure) {
            report($failure);

            return response()->json(['message' => 'The task probe could not run: '.$failure->getMessage()], 500);
        } finally {
            // The lists this run created, dropped whether it finished or threw.
            // See the 0L: the task source cannot delete a task or clear a list,
            // so this reaches past it into the store by key.
            $probe->forget($report['lists']);
        }

        return response()->json([
            ...$report,
            // Filed whichever way it went. A green probe that records nothing
            // teaches nobody, and this workspace's standing rule is that every
            // test leaves a learning even when every lane passes.
            'learning' => $recorder->record($report),
        ]);
    }

    /**
     * The live lane: a real model, refused by the real package.
     */
    public function agent(TaskListProbe $probe, TaskAgentProbe $agent, TaskProbeLearningRecorder $recorder): JsonResponse
    {
        // THE BOARD RUNS FIRST, AND THE ORDER IS LOAD-BEARING.
        //
        // The agent lane registers `complete_task` on the ToolRegistry, which
        // is a process-wide singleton with no way to unregister — so after the
        // lane has run, `resolve(['*'])` in THIS request answers differently
        // than it does in any other. The board's registered-nowhere property
        // would then report red for something the application did not do.
        //
        // Re-run rather than remembered from the last request, too: a 0L
        // pairing a live verdict with property results from some earlier run
        // asserts something nobody checked together.
        $report = $probe->run();
        $probe->forget($report['lists']);

        try {
            $lane = $agent->run();
        } catch (Throwable $failure) {
            report($failure);

            return response()->json(['message' => 'The live agent lane could not run: '.$failure->getMessage()], 502);
        }

        return response()->json([
            'agent' => $lane,
            'properties' => $report['properties'],
            'held' => $report['held'],
            'learning' => $recorder->record($report, $lane),
        ]);
    }
}
