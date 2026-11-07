<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\SessionPreflight;
use Illuminate\Http\JsonResponse;

final class SessionPreflightController extends Controller
{
    public function __invoke(SessionPreflight $preflight): JsonResponse
    {
        return response()->json($preflight->report())->header('Cache-Control', 'private, no-store');
    }
}
