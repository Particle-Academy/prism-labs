<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use Illuminate\Support\Facades\DB;

final class BenchmarkDesigner
{
    /**
     * @param  array<string, mixed>  $specification
     * @param  array<string, mixed>  $rubric
     * @param  list<array{language:string,harness:string,provider:string,model:string}>  $lanes
     * @param  array<string, int|float>  $budgets
     */
    public function draft(string $name, string $archetype, string $surfaceMode, array $specification, array $rubric, array $lanes, array $budgets): BenchmarkSpec
    {
        if (! in_array($surfaceMode, ['standard', 'human_plus'], true)) {
            throw new \InvalidArgumentException('Benchmark surface mode must be standard or human_plus.');
        }
        if ($lanes === []) {
            throw new \InvalidArgumentException('A benchmark must declare at least one lane.');
        }
        foreach ($lanes as $lane) {
            foreach (['language', 'harness', 'provider', 'model'] as $field) {
                if (! isset($lane[$field]) || trim($lane[$field]) === '') {
                    throw new \InvalidArgumentException(sprintf('Every benchmark lane requires [%s].', $field));
                }
            }
        }

        $revision = ((int) BenchmarkSpec::query()->where('name', $name)->max('revision')) + 1;
        $document = compact('name', 'archetype', 'surfaceMode', 'specification', 'rubric', 'lanes', 'budgets', 'revision');

        return BenchmarkSpec::query()->create([
            'revision' => $revision, 'digest' => hash('sha256', CanonicalJson::encode($document)),
            'status' => 'draft', 'name' => $name, 'archetype' => $archetype,
            'surface_mode' => $surfaceMode, 'specification' => $specification,
            'rubric' => $rubric, 'lane_matrix' => $lanes, 'budgets' => $budgets,
        ]);
    }

    public function requestApproval(BenchmarkSpec $spec): BenchmarkSpec
    {
        return $this->transition($spec, 'draft', 'awaiting_approval');
    }

    public function approve(BenchmarkSpec $spec): BenchmarkSpec
    {
        $spec = $this->transition($spec, 'awaiting_approval', 'approved');
        $spec->forceFill(['approved_at' => now()])->save();

        return $spec;
    }

    public function launch(BenchmarkSpec $spec): BenchmarkRun
    {
        if ($spec->status !== 'approved') {
            throw new \LogicException('Only an approved frozen benchmark can launch.');
        }
        $lanes = $spec->lane_matrix;
        shuffle($lanes);

        return DB::transaction(function () use ($spec, $lanes): BenchmarkRun {
            $run = BenchmarkRun::query()->create([
                'benchmark_spec_id' => $spec->id, 'status' => 'queued',
                'randomized_order' => array_map(fn (array $lane): string => implode(':', [$lane['language'], $lane['provider'], $lane['model']]), $lanes),
            ]);
            foreach ($lanes as $ordinal => $lane) {
                BenchmarkLane::query()->create([
                    'benchmark_run_id' => $run->id, 'ordinal' => $ordinal + 1,
                    'language' => $lane['language'], 'harness' => $lane['harness'],
                    'provider' => $lane['provider'], 'model' => $lane['model'], 'status' => 'queued',
                ]);
            }

            return $run;
        });
    }

    private function transition(BenchmarkSpec $spec, string $from, string $to): BenchmarkSpec
    {
        if ($spec->status !== $from) {
            throw new \LogicException(sprintf('Benchmark must be [%s] before it can become [%s].', $from, $to));
        }
        $spec->forceFill(['status' => $to])->save();

        return $spec;
    }
}
