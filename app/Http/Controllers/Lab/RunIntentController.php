<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\RunAuthority;
use App\Lab\RunScope;
use App\Lab\RunScopeFactory;
use App\Models\LabRunIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RunIntentController extends Controller
{
    public function index(Request $request, RunAuthority $authority): JsonResponse
    {
        $input = $request->validate(['before' => ['sometimes', 'required', 'string', 'uuid', 'max:36']]);

        return response()->json($authority->allocations($input['before'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RunAuthority $authority, RunScopeFactory $scopes): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', 'string', Rule::in(RunScope::ACTIONS)],
            'resource' => ['required', 'string', 'max:200'], 'payload' => ['present', 'array'],
            'cap_usd' => ['required', 'string', 'max:16'],
        ]);
        $intent = $authority->draft($scopes->fromAction($input['action'], $input['resource'], $input['payload']), $input['cap_usd']);

        return response()->json($intent, 201)->header('Cache-Control', 'private, no-store');
    }

    public function approve(Request $request, LabRunIntent $intent, RunAuthority $authority): JsonResponse
    {
        $input = $request->validate(['digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return response()->json($authority->approve($intent->id, $input['digest']))->header('Cache-Control', 'private, no-store');
    }

    public function go(Request $request, LabRunIntent $intent, RunAuthority $authority): JsonResponse
    {
        $input = $request->validate([
            'digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'cap_usd' => ['required', 'string', 'max:16'], 'confirmation' => ['required', 'string', 'in:GO'],
        ]);

        return response()->json($authority->go($intent->id, $input['digest'], $input['cap_usd'], $input['confirmation']))->header('Cache-Control', 'private, no-store');
    }

    public function revoke(LabRunIntent $intent, RunAuthority $authority): JsonResponse
    {
        return response()->json($authority->revoke($intent->id))->header('Cache-Control', 'private, no-store');
    }
}
