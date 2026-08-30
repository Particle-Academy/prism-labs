<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\CapabilityManager;
use App\Lab\LabSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Throwable;

final class CapabilityController extends Controller
{
    public function status(Request $request, LabSession $sessions, CapabilityManager $capabilities): JsonResponse
    {
        return response()->json($capabilities->status($sessions->resolve($request)));
    }

    public function openBrowser(Request $request, LabSession $sessions, CapabilityManager $capabilities): JsonResponse
    {
        try {
            return response()->json(['browser' => $capabilities->openBrowser($sessions->resolve($request))]);
        } catch (Throwable $failure) {
            report($failure);

            return response()->json(['message' => 'The local browser service is unavailable. Run php artisan prism-browser:serve and check its token configuration.'], 502);
        }
    }

    public function closeBrowser(Request $request, LabSession $sessions, CapabilityManager $capabilities): JsonResponse
    {
        $capabilities->closeBrowser($sessions->resolve($request));

        return response()->json(['browser' => null]);
    }

    public function attachHumanPlus(Request $request, LabSession $sessions, CapabilityManager $capabilities): JsonResponse
    {
        $input = $request->validate([
            'relay_url' => ['required', 'url:https', 'max:2048'], 'session_id' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'min:16', 'max:4096'], 'surface_id' => ['required', 'string', 'max:120'],
            'application' => ['required', 'string', 'max:120'],
        ]);
        try {
            $surface = $capabilities->attachHumanPlus($sessions->resolve($request), new SurfaceInvitation(
                $input['relay_url'], $input['session_id'], $input['token'], $input['surface_id'], $input['application'],
            ));

            return response()->json(['human_plus' => $surface]);
        } catch (Throwable $failure) {
            report($failure);

            return response()->json(['message' => 'The Human+ surface refused the invitation or did not match local trust policy.'], 502);
        }
    }

    public function detachHumanPlus(Request $request, LabSession $sessions, CapabilityManager $capabilities): JsonResponse
    {
        $capabilities->detachHumanPlus($sessions->resolve($request));

        return response()->json(['human_plus' => null]);
    }
}
