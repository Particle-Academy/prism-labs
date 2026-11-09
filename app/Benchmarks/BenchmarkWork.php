<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\LabPaidWork;

/** Actual persisted business inputs, recomputed before a delayed paid call. */
final class BenchmarkWork
{
    public static function runPayload(BenchmarkRun $run): array
    {
        $run->refresh();

        return ['spec_id' => $run->benchmark_spec_id, 'spec_digest' => $run->spec->digest,
            'approved_run_id' => $run->approved_run_id,
            'lanes' => $run->lanes()->orderBy('ordinal')->get()->map(fn (BenchmarkLane $lane): array => [
                'id' => $lane->id, 'language' => $lane->language, 'harness' => $lane->harness,
                'provider' => $lane->provider, 'model' => $lane->model,
            ])->all()];
    }

    public static function lanePayload(BenchmarkLane $lane): array
    {
        $lane->refresh();

        return ['run_id' => $lane->benchmark_run_id, 'lane_id' => $lane->id, 'language' => $lane->language,
            'harness' => $lane->harness, 'provider' => $lane->provider, 'model' => $lane->model];
    }

    public static function scorePayload(BenchmarkLane $lane): array
    {
        $lane->refresh();

        return ['lane_id' => $lane->id,
            'proof_digest' => hash('sha256', CanonicalJson::encode($lane->proof)),
            'receipts_digest' => hash('sha256', CanonicalJson::encode($lane->receipts()->get()
                ->map(fn ($receipt): array => ['id' => $receipt->id, 'kind' => $receipt->kind, 'digest' => $receipt->digest,
                    'payload' => $receipt->payload, 'created_at' => $receipt->getRawOriginal('created_at')])->all()))];
    }

    public static function assertRun(BenchmarkRun $run): void
    {
        $run->refresh();
        $intent = app(PaidRunContext::class)->authorizedRun(['benchmarks.launch']);
        if ($run->approved_run_id !== $intent->id || $run->benchmark_spec_id !== $intent->resource || $run->status === 'cancelled') {
            throw new PaidRunNotAuthorised;
        }
    }

    public static function bindScore(BenchmarkLane $lane): LabPaidWork
    {
        self::assertRun($lane->benchmarkRun);
        app(PaidRunContext::class)->authorizedWork('benchmark.lane', $lane->id, self::lanePayload($lane));

        return app(PaidWorkRegistry::class)->bind('benchmark.score', $lane->id, self::scorePayload($lane), $lane->paid_work_id);
    }

    public static function commentaryKey(string $runId, int $sequence): string
    {
        if ($sequence < 1) {
            throw new PaidRunNotAuthorised;
        }

        return $runId.':'.$sequence;
    }

    public static function commentaryPayload(string $runId, int $sequence): array
    {
        self::commentaryKey($runId, $sequence);

        return ['run_id' => $runId, 'sequence' => $sequence];
    }

    public static function bindCommentary(BenchmarkRun $run, int $sequence): LabPaidWork
    {
        self::assertRun($run);
        $registry = app(PaidWorkRegistry::class);
        $root = $registry->checkProducer($run->paid_work_id, 'benchmark.run', $run->id, self::runPayload($run));
        if ($root->claimed_at === null) {
            throw new PaidRunNotAuthorised;
        }
        if ($sequence > 1) {
            app(PaidRunContext::class)->requireWork('benchmark.commentary', self::commentaryKey($run->id, $sequence - 1),
                self::commentaryPayload($run->id, $sequence - 1));
        }

        return $registry->bind('benchmark.commentary', self::commentaryKey($run->id, $sequence), self::commentaryPayload($run->id, $sequence), $root->id);
    }
}
