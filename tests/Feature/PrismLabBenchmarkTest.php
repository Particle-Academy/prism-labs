<?php

namespace Tests\Feature;

use App\Lab\BenchmarkStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Tests\TestCase;

class PrismLabBenchmarkTest extends TestCase
{
    public function test_it_keeps_benchmark_routes_out_of_non_local_environments(): void
    {
        $this->expectException(RouteNotFoundException::class);

        route('lab.benchmarks', absolute: false);
    }

    public function test_it_keeps_the_benchmark_export_out_of_non_local_environments(): void
    {
        $this->expectException(RouteNotFoundException::class);

        route('lab.benchmarks.export', absolute: false);
    }

    public function test_it_aggregates_recorded_runs_by_provider_model_and_feature(): void
    {
        Storage::fake('local');
        $store = new BenchmarkStore;

        $store->record([
            $this->sample(passed: true, latency: 100.0, prompt: 10, completion: 5, cost: 0.001),
            $this->sample(passed: false, latency: 300.0, prompt: 20, completion: 15, cost: 0.003),
        ]);

        $rows = $store->aggregate();

        $this->assertCount(1, $rows, 'Runs of the same provider+model+feature collapse into one row.');
        $row = $rows[0];

        $this->assertSame('openai', $row['provider']);
        $this->assertSame('gpt-4.1-mini', $row['model']);
        $this->assertSame('text', $row['feature']);
        $this->assertSame(2, $row['runs']);
        $this->assertSame(1, $row['passed']);
        $this->assertSame(50.0, $row['pass_rate']);
        $this->assertSame(200.0, $row['avg_latency_ms']);
        $this->assertSame(300.0, $row['p95_latency_ms']);
        $this->assertSame(15.0, $row['avg_prompt_tokens']);
        $this->assertSame(10.0, $row['avg_completion_tokens']);
        $this->assertSame(0.004, $row['total_cost']);
    }

    public function test_it_separates_distinct_models_and_features(): void
    {
        Storage::fake('local');
        $store = new BenchmarkStore;

        $store->record([
            $this->sample(),
            $this->sample(feature: 'tools'),
            $this->sample(model: 'gpt-4o'),
        ]);

        $this->assertCount(3, $store->aggregate());
    }

    public function test_it_exports_a_publishable_artifact(): void
    {
        Storage::fake('local');
        $store = new BenchmarkStore;
        $store->record([$this->sample(), $this->sample()]);

        $export = $store->export();

        $this->assertSame('Prism Lab — real provider generations (local)', $export['source']);
        $this->assertSame(2, $export['total_runs']);
        $this->assertCount(1, $export['benchmarks']);
        $this->assertNotEmpty($export['generated_at']);
    }

    public function test_it_returns_no_benchmarks_before_any_run(): void
    {
        Storage::fake('local');

        $store = new BenchmarkStore;

        $this->assertSame([], $store->aggregate());
        $this->assertSame(0, $store->all()->count());
    }

    /** @return array<string, mixed> */
    private function sample(bool $passed = true, float $latency = 120.0, int $prompt = 10, int $completion = 5, ?float $cost = 0.001, string $model = 'gpt-4.1-mini', string $feature = 'text'): array
    {
        return [
            'id' => "openai.{$feature}",
            'provider' => 'openai',
            'model' => $model,
            'feature' => $feature,
            'passed' => $passed,
            'latency_ms' => $latency,
            'metrics' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'provider_reported_cost' => $cost,
            ],
        ];
    }
}
