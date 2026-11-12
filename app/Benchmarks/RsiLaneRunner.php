<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\LabSession;
use App\Lab\PaidRunNotAuthorised;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Exceptions\RunNotPermitted;

/** Native bounded inference plus deterministic measurements, without judgments. */
final readonly class RsiLaneRunner
{
    public function __construct(private RsiLaneProfile $profile, private RsiCostAdmission $admission, private LabSession $sessions, private RsiInstruments $instruments) {}

    public function run(BenchmarkLane $lane, BenchmarkSpec $spec): array
    {
        $lane->refresh();
        $spec->refresh();
        app(BenchmarkDesigner::class)->assertFrozen($spec);
        if ($spec->archetype !== FrozenTestDefinition::ARCHETYPE || $lane->benchmarkRun->benchmark_spec_id !== $spec->id
            || ! is_array($lane->frozen_test_lane) || ! in_array(CanonicalJson::encode($lane->frozen_test_lane), array_map(CanonicalJson::encode(...), $spec->lane_matrix), true)) {
            throw new PaidRunNotAuthorised;
        }
        $failures = $this->profile->failures($lane->frozen_test_lane, $spec->specification);
        if ($failures !== []) {
            return ['ok' => false, 'classification' => 'unsupported', 'reason' => implode(' ', $failures)];
        }
        $context = $this->admission->context($lane);
        $bounds = $lane->frozen_test_lane['bounds'];
        $started = hrtime(true);
        try {
            $session = $this->sessions->resolveScope('rsi:'.$lane->benchmark_run_id.':'.$lane->id)
                ->usingMode($lane->frozen_test_lane['mode'])->usingProvider($lane->provider)->usingModel($lane->model)
                ->usingMaxSteps(min($bounds['max_calls'], $bounds['max_steps']));
            $threadId = (string) $session->thread()->getKey();
            $started = hrtime(true);
            $response = $session->send($spec->specification['brief'], toolNames: [], context: $context);
        } catch (RunNotPermitted $refusal) {
            return ['ok' => false, 'classification' => 'admission', 'code' => $refusal->code(), 'reason' => 'Native cost admission refused this lane.'];
        } catch (ConnectionException|RequestException) {
            return ['ok' => false, 'classification' => 'environment', 'reason' => 'The provider could not complete this lane. No package verdict or automatic retry.'];
        } catch (\Throwable $failure) {
            return ['ok' => false, 'classification' => 'unknown', 'failure_class' => $failure::class, 'reason' => 'The native lane did not complete. Error content is not exported.'];
        }
        $duration = (hrtime(true) - $started) / 1000000;
        $text = $response->text();
        $calls = DB::table('lab_call_reservations')->where('paid_work_id', $lane->paid_work_id)->get();
        $known = $calls->isNotEmpty() && $calls->every(fn ($call) => $call->settled_at !== null && (bool) $call->usage_known);
        $usage = $response->response->usage;
        // Core's all-zero/no-cost fallback is not an observed usage record.
        $hasUsage = max($usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens ?? 0, $usage->cacheWriteInputTokens ?? 0) > 0
            || ($usage->cost !== null && is_finite($usage->cost) && $usage->cost >= 0);
        $metrics = ['native_send_ms' => $duration, 'calls' => $calls->count(),
            'prompt_tokens' => $hasUsage && $usage->promptTokens >= 0 ? $usage->promptTokens : null,
            'completion_tokens' => $hasUsage && $usage->completionTokens >= 0 ? $usage->completionTokens : null,
            'known_charge_microusd' => $known ? (int) $calls->sum('charged_microusd') : null];
        $observed = ['spec_digest' => $spec->digest, 'judgment' => 'unjudged', 'run_id' => $response->runId,
            'thread_id' => $threadId, 'trace_id' => null, 'metrics' => $metrics,
            'observed_lane' => ['language' => 'php', 'package_ref' => $this->profile->installedReference(),
                'harness_ref' => $this->profile->installedReference(), 'provider' => $lane->provider, 'model' => $lane->model,
                'mode' => $lane->frozen_test_lane['mode'], 'agent' => null],
            'output_sha256' => hash('sha256', $text), 'output_bytes' => strlen($text),
            'instrument_results' => $this->instruments->measure($spec->specification, $text, $metrics),
            'classification' => 'observed', 'artifact_content_captured' => false];
        // Lock the run like the fuse, so a late response cannot overwrite cancelled.
        DB::transaction(function () use ($lane, $observed): void {
            $run = BenchmarkRun::query()->lockForUpdate()->findOrFail($lane->benchmark_run_id);
            if ($run->status === 'cancelled') {
                return;
            }
            BenchmarkLane::query()->whereKey($lane->id)->where('status', 'running')->update([
                'status' => 'completed', 'proof' => json_encode($observed, JSON_THROW_ON_ERROR), 'finished_at' => now(), 'updated_at' => now(),
            ]);
        });
        $lane->refresh();

        return ['ok' => $lane->status === 'completed', ...$observed];
    }
}
