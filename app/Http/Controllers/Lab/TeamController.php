<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use App\Learnings\Learning;
use App\Team\Coordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The board: who is on the team, what they have learned, and a way to talk to
 * the coordinator directly.
 *
 * The roster is NOT probed on page load. Probing means a network call per
 * addressable lane, and a lane that is down would hold the whole page behind
 * its timeout. The page renders the roster's declared shape immediately and
 * asks for live state separately, so a dead agent costs one card rather than
 * the board.
 */
final class TeamController extends Controller
{
    public function show(Coordinator $coordinator): Response
    {
        return Inertia::render('Lab/Team', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'learnings' => $this->learnings(),
        ]);
    }

    /**
     * Live state for every lane. Called after paint, and on demand.
     */
    public function roster(Coordinator $coordinator): JsonResponse
    {
        return response()->json(['roster' => $coordinator->roster()]);
    }

    public function ask(Request $request, Coordinator $coordinator): JsonResponse
    {
        // Validated by hand rather than through $request->validate(). This route
        // is in the web group, so Inertia turns a validation failure into a 302
        // back to the page — right for a form post, wrong for a JSON endpoint
        // the page calls with fetch().
        $validator = Validator::make($request->all(), [
            'question' => ['required', 'string', 'max:4000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('question')], 422);
        }

        try {
            $result = $coordinator->ask((string) $validator->validated()['question']);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Prism could not complete that: '.$e->getMessage(),
            ], 502);
        }

        return response()->json([
            ...$result,
            // Re-read rather than returned from the run: the coordinator may
            // have filed one mid-answer, and the feed should show it without
            // the page having to guess whether it did.
            'learnings' => $this->learnings(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function learnings(): array
    {
        return Learning::query()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Learning $l): array => [
                'ref' => $l->ref,
                'title' => $l->title,
                'filed_by' => $l->filed_by,
                'severity' => $l->severity->value,
                'severity_label' => $l->severity->label(),
                'languages' => $l->languages,
                'what_was_learned' => $l->what_was_learned,
                'evidence' => $l->evidence,
                'why_it_matters' => $l->why_it_matters,
                'what_should_change' => $l->what_should_change,
                'filed_at' => $l->created_at?->diffForHumans(),
            ])
            ->all();
    }
}
