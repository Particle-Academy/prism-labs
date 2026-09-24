<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\ProviderFeatureProbe;
use App\Research\Researcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProviderProbeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Lab/ProviderProbes');
    }

    public function research(Request $request, Researcher $researcher): JsonResponse
    {
        $input = $request->validate(['question' => ['required', 'string', 'max:10000']]);

        return response()->json($researcher->ask($input['question']))->header('Cache-Control', 'no-store');
    }

    public function fetch(Request $request, ProviderFeatureProbe $probe): JsonResponse
    {
        // Let the package classify schemes and hosts, so refusals reach the UI.
        $input = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        return response()->json($probe->fetch($input['url']))->header('Cache-Control', 'no-store');
    }

    public function cache(Request $request, ProviderFeatureProbe $probe): JsonResponse
    {
        $input = $request->validate([
            'model' => ['required', 'string', 'max:120'],
            'prefix' => ['required', 'string', 'max:100000'],
            'question' => ['required', 'string', 'max:2000'],
            'follow_up' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json($probe->cache($input['model'], $input['prefix'], $input['question'], $input['follow_up']))->header('Cache-Control', 'no-store');
    }
}
