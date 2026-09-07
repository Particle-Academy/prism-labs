<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Benchmarks\CompactionReservationProbe;
use App\Http\Controllers\Controller;
use App\Models\CompactionProbeRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Run the compaction probe FROM THE LAB, and keep every result.
 *
 * This exists because the probe was first built as a headless endpoint driven
 * by a script outside the repository, which meant the one place the ecosystem
 * is supposed to be dogfooded — the Lab, in a browser — showed nothing at all.
 * A guarantee nobody can see the state of is not being watched.
 *
 * The run is synchronous and slow (it drives a real multi-round agent loop
 * against a live provider, deliberately, because compaction only happens on a
 * real transcript). That is why it is a button rather than something on page
 * load: it spends money every time it is pressed.
 */
final class CompactionProbeController extends Controller
{
    public function store(Request $request, CompactionReservationProbe $probe): RedirectResponse
    {
        $validated = $request->validate([
            'reserved' => ['nullable', 'boolean'],
            'trigger' => ['nullable', 'integer', 'min:1000', 'max:200000'],
            'keep' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $reserved = (bool) ($validated['reserved'] ?? true);

        $result = $probe->run(
            trigger: (int) ($validated['trigger'] ?? 5000),
            keep: (int) ($validated['keep'] ?? 3),
            reserve: $reserved,
        );

        CompactionProbeRun::query()->create([
            'verdict' => $result['verdict'],
            'reserved' => $result['reserved'],
            'model' => $result['model'],
            'steps' => $result['steps'],
            'ledger_reads' => $result['ledger_reads'],
            'attempts' => $result['attempts'],
            'executions' => $result['executions'],
            'tool_uses_cleared' => $result['tool_uses_cleared'],
            'denials' => $result['denials'],
            'duration_ms' => $result['duration_ms'],
            'failure' => $result['failure'],
        ]);

        // A BREACH is stated as a breach, not as "completed". The one outcome
        // this whole surface exists to catch must not read like a normal run.
        $message = match (true) {
            $result['verdict'] === 'BREACH' => 'BREACH — a reserved tool EXECUTED while the window was compacted.',
            $result['verdict'] === 'held' => sprintf(
                'Held — %d attempts refused across %d cleared tool uses.',
                $result['attempts'],
                $result['tool_uses_cleared'],
            ),
            default => 'Recorded: '.$result['verdict'],
        };

        return back()->with($result['verdict'] === 'BREACH' ? 'error' : 'status', $message);
    }
}
