<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\NativeCostObserver;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\LabRunIntent;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;

/** Host authority + durable native hooks; it never performs a provider call. */
final readonly class RsiCostAdmission
{
    public function context(BenchmarkLane $lane): RunContext
    {
        $intent = $this->authorize($lane);
        $definition = $lane->fresh()->frozen_test_lane;
        $bounds = $definition['bounds'];
        app(PaidRunContext::class)->requireWork('benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane));
        $work = app(PaidWorkRegistry::class)->checkProducer($lane->paid_work_id, 'benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane));
        if ($work->claimed_at === null || $work->finished_at !== null) {
            throw new PaidRunNotAuthorised;
        }
        $observer = new NativeCostObserver($intent->id, $intent->authority_session_id, $work->id, $work->payload_digest,
            $lane->benchmark_run_id, $lane->provider, $lane->model,
            function () use ($lane, $work, $bounds): LabRunIntent {
                $current = $this->authorize($lane);
                $actual = app(PaidWorkRegistry::class)->checkProducer($lane->paid_work_id, 'benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane));
                if ($actual->id !== $work->id || $actual->payload_digest !== $work->payload_digest || $actual->claimed_at === null || $actual->finished_at !== null
                    || $lane->status !== 'running' || $lane->started_at === null || $lane->started_at->copy()->addSeconds($bounds['timeout_seconds'])->isPast()
                    || app(RsiLaneProfile::class)->failures($lane->frozen_test_lane, $lane->benchmarkRun->spec->specification) !== []) {
                    throw new CallRefused('allocation_identity_mismatch');
                }
                if (DB::table('lab_call_reservations')->where('paid_work_id', $work->id)->count() >= $bounds['max_calls']) {
                    throw new CallRefused('call_limit_exhausted');
                }

                return $current;
            });

        return RunContext::root($lane->benchmark_run_id,
            new RunBudget(min($bounds['max_calls'], $bounds['max_steps']), $intent->cap_microusd / 1000000,
                maxSeconds: $bounds['timeout_seconds'], maxOutputTokensPerCall: $bounds['limits']['max_output_tokens'] ?? null),
            (array) config('prism-harness.pricing', []), $observer);
    }

    private function authorize(BenchmarkLane $lane): LabRunIntent
    {
        $lane->refresh();
        $run = BenchmarkRun::query()->findOrFail($lane->benchmark_run_id);
        BenchmarkWork::assertRun($run);
        $intent = app(PaidRunContext::class)->authorizedWork('benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane));
        $spec = $run->spec;
        $snapshot = app(BenchmarkDesigner::class)->approvedSnapshot($spec);
        if ($spec->archetype !== FrozenTestDefinition::ARCHETYPE || $intent->cap_microusd !== $spec->budgets['cost_cap_microusd']
            || CanonicalJson::encode($snapshot) !== CanonicalJson::encode($intent->payload['benchmark'] ?? null)
            || ! is_array($lane->frozen_test_lane) || ! in_array(CanonicalJson::encode($lane->frozen_test_lane), array_map(CanonicalJson::encode(...), $spec->lane_matrix), true)
            || $lane->language !== $lane->frozen_test_lane['language'] || $lane->provider !== $lane->frozen_test_lane['provider']
            || $lane->model !== $lane->frozen_test_lane['model'] || $lane->harness !== $lane->frozen_test_lane['harness']) {
            throw new PaidRunNotAuthorised;
        }

        return $intent;
    }
}
