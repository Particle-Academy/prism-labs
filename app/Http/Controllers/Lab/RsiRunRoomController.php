<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Benchmarks\RsiRunEvidence;
use App\Http\Controllers\Controller;
use App\Models\BenchmarkRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RsiRunRoomController extends Controller
{
    public function show(Request $request, BenchmarkRun $run, RsiRunEvidence $evidence): Response
    {
        return Inertia::render('Lab/RsiRunRoom', ['evidence' => $this->read($request, $run, $evidence)]);
    }

    public function export(Request $request, BenchmarkRun $run, RsiRunEvidence $evidence): JsonResponse
    {
        return response()->json($this->read($request, $run, $evidence), 200,
            ['Content-Disposition' => 'attachment; filename="rsi-run-'.$run->id.'.json"', 'Cache-Control' => 'private, no-store']);
    }

    private function read(Request $request, BenchmarkRun $run, RsiRunEvidence $evidence): array
    {
        $input = $request->validate(['lane' => ['nullable', 'uuid']]);

        return $evidence->present($run, $input['lane'] ?? null);
    }
}
