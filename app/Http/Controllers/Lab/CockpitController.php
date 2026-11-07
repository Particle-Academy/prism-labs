<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\SessionPreflight;
use App\Models\BenchmarkRun;
use App\Models\ConsensusRun;
use App\Models\LabOperation;
use App\Telemetry\OperationLedger;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class CockpitController extends Controller
{
    public function __invoke(OperationLedger $ledger, SessionPreflight $preflight): Response
    {
        $snapshot = $preflight->report();
        $data = ['metrics' => null, 'benchmarks' => [], 'consensus' => [], 'recent' => [], 'dataAvailable' => false];
        if ($snapshot['checks']['database']['ready']) {
            try {
                $daily = $ledger->burn('daily');
                $data = [
                    'metrics' => [
                        'activeRuns' => BenchmarkRun::query()->whereIn('status', ['queued', 'ready', 'running'])->count()
                            + ConsensusRun::query()->whereIn('status', ['collecting', 'awaiting_review'])->count(),
                        // Reasoning tokens are a breakdown of output, not an addition.
                        'dailyTokens' => (int) (($daily['input_tokens'] ?? 0) + ($daily['output_tokens'] ?? 0)),
                        'dailyCost' => (float) ($daily['priced_cost'] ?? 0),
                        'operations' => (int) ($daily['operations'] ?? 0),
                    ],
                    'benchmarks' => BenchmarkRun::query()->with('spec')->latest()->limit(4)->get(),
                    'consensus' => ConsensusRun::query()->latest()->limit(4)->get(),
                    'recent' => LabOperation::query()->latest('started_at')->limit(6)->get([
                        'id', 'kind', 'provider', 'model', 'status', 'duration_ms', 'cost', 'started_at',
                    ]),
                    'dataAvailable' => true,
                ];
            } catch (Throwable) {
                // Setup may change between the check and the ledger queries.
                $snapshot['ready'] = false;
                $snapshot['checks']['database'] = ['ready' => false, 'status' => 'unavailable', 'message' => 'Lab database data is unavailable.'];
            }
        }

        return Inertia::render('Lab/Cockpit', ['preflight' => $snapshot, ...$data]);
    }
}
