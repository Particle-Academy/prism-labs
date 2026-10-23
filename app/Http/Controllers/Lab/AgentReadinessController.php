<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Team\AgentReadiness;
use Illuminate\Http\JsonResponse;

final class AgentReadinessController extends Controller
{
    public function __invoke(AgentReadiness $readiness): JsonResponse
    {
        return response()->json(['lanes' => $readiness->lanes()])->header('Cache-Control', 'no-store');
    }
}
