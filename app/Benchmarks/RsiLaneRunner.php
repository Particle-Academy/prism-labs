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

/** Native, bounded inference only. Instruments and judgments are separate. */
final readonly class RsiLaneRunner
{
    public function __construct(private RsiLaneProfile $profile, private RsiCostAdmission $admission, private LabSession $sessions) {}

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
        try {
            $response = $this->sessions->resolveScope('rsi:'.$lane->benchmark_run_id.':'.$lane->id)
                ->usingMode($lane->frozen_test_lane['mode'])->usingProvider($lane->provider)->usingModel($lane->model)
                ->usingMaxSteps(min($bounds['max_calls'], $bounds['max_steps']))
                ->send($spec->specification['brief'], toolNames: [], context: $context);
        } catch (RunNotPermitted $refusal) {
            return ['ok' => false, 'classification' => 'admission', 'code' => $refusal->code(), 'reason' => 'Native cost admission refused this lane.'];
        } catch (ConnectionException|RequestException) {
            return ['ok' => false, 'classification' => 'environment', 'reason' => 'The provider could not complete this lane. No package verdict or automatic retry.'];
        } catch (\Throwable $failure) {
            return ['ok' => false, 'classification' => 'unknown', 'failure_class' => $failure::class, 'reason' => 'The native lane did not complete. Error content is not exported.'];
        }
        $text = $response->text();
        $observed = ['spec_digest' => $spec->digest, 'judgment' => 'unjudged', 'run_id' => $response->runId,
            'output_sha256' => hash('sha256', $text), 'output_bytes' => strlen($text),
            'instrument_results' => null, 'classification' => 'observed', 'artifact_content_captured' => false];
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
