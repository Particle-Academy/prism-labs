<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\BenchmarkStore;
use App\Lab\InstalledVersions;
use App\Lab\PrismTestRegistry;
use App\Lab\PrismTestRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TestSuiteController extends Controller
{
    public function show(PrismTestRegistry $registry): Response
    {
        return Inertia::render('Lab/Tests', [
            'version' => InstalledVersions::prism(),
            'packages' => InstalledVersions::all(),
            'cases' => $registry->all()->map->toArray()->values(),
            'availability' => [
                'openai' => filled(config('prism.providers.openai.api_key')),
                'anthropic' => filled(config('prism.providers.anthropic.api_key')),
            ],
            'phoenixUrl' => rtrim((string) env('PHOENIX_UI_URL', 'http://localhost:6006'), '/'),
        ]);
    }

    public function run(Request $request, PrismTestRegistry $registry, PrismTestRunner $runner, BenchmarkStore $benchmarks): JsonResponse
    {
        $input = $request->validate([
            'cases' => ['required', 'array', 'min:1', 'max:10'],
            'cases.*' => ['string', 'distinct'],
        ]);
        $cases = collect($input['cases'])->map(fn (string $id) => $registry->find($id));

        if ($cases->contains(null)) {
            return response()->json(['message' => 'One or more test cases are unknown.'], 422);
        }

        $results = $cases->map(fn ($case) => $runner->run($case))->values();

        // Feed the benchmark history so /lab/benchmarks can compare over time.
        $benchmarks->record($results->all());

        return response()->json(['results' => $results]);
    }
}
