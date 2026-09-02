<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Lab\ModelCatalogue;
use App\Lab\ModelPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ModelPolicyController extends Controller
{
    public function show(ModelCatalogue $catalogue, ModelPolicy $policy): Response
    {
        return Inertia::render('Lab/Models', [
            'models' => $catalogue->all(),
            'allowed' => $policy->allowed(),
        ]);
    }

    public function update(Request $request, ModelPolicy $policy): RedirectResponse
    {
        $input = $request->validate([
            'allowed' => ['present', 'array'],
            'allowed.*' => ['string', 'max:120'],
        ]);

        // An empty selection is ALLOWED and is not the same as no opinion: it
        // means every benchmark is refused, which is a legitimate way to stop
        // spend without deleting specs. Unknown keys are dropped rather than
        // rejected, so a stale form cannot fail the whole save.
        $stored = $policy->allow(array_values($input['allowed']));

        return to_route('lab.models')->with('status', $stored === []
            ? 'No models are enabled. Every benchmark launch will be refused until one is.'
            : sprintf('%d model(s) enabled for benchmark lanes.', count($stored)));
    }
}
