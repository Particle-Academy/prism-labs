<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Consensus\ConsensusCoordinator;
use App\Http\Controllers\Controller;
use App\Models\ConsensusRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ConsensusController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Lab/Consensus', [
            'runs' => ConsensusRun::query()->latest()->limit(20)->get(),
        ]);
    }

    public function store(Request $request, ConsensusCoordinator $coordinator): RedirectResponse
    {
        $input = $request->validate([
            'question' => ['required', 'string', 'max:12000'],
            'evidence' => ['nullable', 'string', 'max:20000'],
        ]);

        $coordinator->collect($input['question'], ['brief' => $input['evidence'] ?? '']);

        return to_route('lab.consensus');
    }

    public function review(Request $request, ConsensusRun $run, ConsensusCoordinator $coordinator): RedirectResponse
    {
        $input = $request->validate(['synthesis' => ['required', 'string', 'max:20000']]);
        $coordinator->review($run, $input['synthesis']);

        return to_route('lab.consensus');
    }
}
