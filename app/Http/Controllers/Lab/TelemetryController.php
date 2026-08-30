<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\InstalledVersions;
use App\Models\LabOperation;
use App\Telemetry\OperationLedger;
use Inertia\Inertia;
use Inertia\Response;

final class TelemetryController extends Controller
{
    public function show(OperationLedger $ledger): Response
    {
        return Inertia::render('Lab/Telemetry', [
            'version' => InstalledVersions::prism(),
            'daily' => $ledger->burn('daily'),
            'monthly' => $ledger->burn('monthly'),
            'recent' => LabOperation::query()->latest('started_at')->limit(50)->get([
                'id', 'kind', 'provider', 'model', 'language', 'status', 'duration_ms',
                'input_tokens', 'output_tokens', 'reasoning_tokens', 'cost', 'cost_source', 'started_at',
            ]),
        ]);
    }
}
