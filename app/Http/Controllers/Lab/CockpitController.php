<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\BenchmarkRun;
use App\Models\ConsensusRun;
use App\Models\LabOperation;
use App\Telemetry\OperationLedger;
use Inertia\Inertia;
use Inertia\Response;

final class CockpitController extends Controller
{
    public function __invoke(OperationLedger $ledger): Response
    {
        $daily = $ledger->burn('daily');

        return Inertia::render('Lab/Cockpit', [
            'metrics' => [
                'activeRuns' => BenchmarkRun::query()->whereIn('status', ['queued', 'ready', 'running'])->count()
                    + ConsensusRun::query()->whereIn('status', ['collecting', 'awaiting_review'])->count(),
                // input + output only. Reasoning tokens are a BREAKDOWN of the
                // output count, not an addition -- see the note in
                // Pages/Lab/Telemetry.tsx. Adding them counts the expensive
                // half twice, which stayed invisible only while Anthropic's
                // thoughtTokens was always null.
                'dailyTokens' => (int) (($daily['input_tokens'] ?? 0) + ($daily['output_tokens'] ?? 0)),
                'dailyCost' => (float) ($daily['priced_cost'] ?? 0),
                'operations' => (int) ($daily['operations'] ?? 0),
            ],
            'benchmarks' => BenchmarkRun::query()->with('spec')->latest()->limit(4)->get(),
            'consensus' => ConsensusRun::query()->latest()->limit(4)->get(),
            'recent' => LabOperation::query()->latest('started_at')->limit(6)->get([
                'id', 'kind', 'provider', 'model', 'status', 'duration_ms', 'cost', 'started_at',
            ]),
        ]);
    }
}
