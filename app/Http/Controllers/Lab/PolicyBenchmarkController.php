<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Benchmarks\PolicyAdherenceAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * One agent turn, for an external policy-adherence benchmark to drive.
 *
 * The benchmark owns the conversation, the environment and the score. This
 * endpoint owns exactly one thing: given the policy, the tools and the
 * transcript so far, what does PRISM do next.
 *
 * That split is the point. Running the benchmark against the provider directly
 * would measure the provider; running it through here measures the thing we
 * ship. If context management degrades adherence, we want to find that out
 * about our own stack rather than infer it.
 *
 * Local-only, like every other lab route — it is behind
 * `EnsurePrismLabIsLocal`. It spends provider tokens on every call, which is
 * reason enough not to expose it.
 */
final class PolicyBenchmarkController extends Controller
{
    public function turn(Request $request, PolicyAdherenceAgent $agent): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'model' => ['required', 'string'],
            'policy' => ['required', 'string'],
            'tools' => ['present', 'array'],
            'messages' => ['present', 'array'],
            'context_management' => ['nullable', 'array'],
        ]);

        try {
            return response()->json($agent->turn(
                provider: $validated['provider'],
                model: $validated['model'],
                policy: $validated['policy'],
                tools: $validated['tools'],
                messages: $validated['messages'],
                contextManagement: $validated['context_management'] ?? null,
            ));
        } catch (Throwable $failure) {
            // Returned rather than thrown, and NAMED. A benchmark run is
            // hundreds of turns; one that dies on an opaque 500 costs the whole
            // run and says nothing about why. The harness can decide whether to
            // retry or record the turn as a failure, which is its call to make.
            return response()->json([
                'error' => $failure::class,
                'message' => $failure->getMessage(),
            ], 502);
        }
    }
}
