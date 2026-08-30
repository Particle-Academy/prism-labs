<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkLane;
use App\Models\BenchmarkLaneActivity;

final class LaneActivity
{
    /** @param array<string, mixed> $detail */
    public function record(BenchmarkLane $lane, string $kind, string $summary, array $detail = [], string $level = 'info'): void
    {
        BenchmarkLaneActivity::query()->create([
            'benchmark_lane_id' => $lane->id,
            'kind' => $kind,
            'level' => $level,
            'summary' => mb_substr($summary, 0, 500),
            'detail' => $detail === [] ? null : $detail,
        ]);
    }
}
