<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Evals\AttestationVerifier;
use App\Evals\EvalImportRefused;
use App\Evals\GhAttestationVerifier;
use App\Evals\ResultsImporter;
use App\Lab\ModelPrices;
use App\Models\EvalMetric;
use App\Models\EvalRun;
use App\Models\EvalTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * G-393: harness-bench results reach the Lab only through a verified attestation.
 *
 * The verifier is faked here (nothing in this suite calls gh or a provider);
 * what is pinned is the ORDER -- an unverified file is refused before it is
 * parsed -- and what one approval may import.
 */
final class HarnessBenchImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        $this->dir = sys_get_temp_dir().'/g393-import-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function results(array $override = []): array
    {
        $tokens = ['fresh_input' => 1000, 'cache_write' => 100, 'cache_read' => 3000, 'output' => 200];
        $summary = ['attempts' => 2, 'solved' => 1, 'score' => 0.5, 'usd_per_solved' => 0.4, 'tokens_per_attempt' => $tokens,
            'cache_read_share' => 0.7317, 'steps_per_attempt' => 12.5];

        return array_replace([
            'schema' => ResultsImporter::SCHEMA, 'ftq_id' => 'q_1c8e9b0d', 'plan_path' => 'evals/plans/g393-calibration.json',
            'plan_digest' => 'sha256:'.str_repeat('a', 64), 'cap_usd' => 100, 'spent_usd' => 0.8,
            'model' => ['provider' => 'anthropic', 'id' => 'claude-haiku-5-5', 'reasoning_effort' => 'default'],
            'harness' => ['version' => '0.26.9'], 'run' => ['run_id' => '123', 'sha' => str_repeat('b', 40)],
            'trials' => [
                ['benchmark' => 'terminal_bench', 'arm' => 'prism', 'task_id' => 'cad-model', 'trial' => 0, 'status' => 'solved',
                    'reward' => 1.0, 'tokens' => $tokens, 'cost_usd' => 0.2, 'steps' => 10, 'wall_seconds' => 50],
                ['benchmark' => 'terminal_bench', 'arm' => 'prism', 'task_id' => 'music-harmony', 'trial' => 0, 'status' => 'cap_stopped',
                    'reward' => 1.0, 'tokens' => $tokens, 'cost_usd' => 0.2, 'steps' => 15, 'wall_seconds' => 70],
            ],
            'metrics' => ['terminal_bench' => [
                'arms' => ['prism' => $summary, 'terminus-2' => $summary],
                'deltas' => ['prism_vs_terminus-2' => ['shared_tasks' => ['cad-model', 'music-harmony'], 'delta' => ['score' => 0.0, 'usd_per_solved' => -0.1]]],
            ]],
        ], $override);
    }

    private function write(array $results): string
    {
        $path = $this->dir.'/results-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, json_encode($results, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function importer(bool $verifies, ?array &$seen = null): ResultsImporter
    {
        $seen = [];
        $verifier = new class($verifies, $seen) implements AttestationVerifier
        {
            public function __construct(private bool $verifies, private array &$seen) {}

            public function verify(string $absolutePath): void
            {
                $this->seen[] = $absolutePath;
                if (! $this->verifies) {
                    throw new EvalImportRefused('no attestation');
                }
            }
        };

        return new ResultsImporter($verifier);
    }

    public function test_a_verified_file_imports_its_trials_and_metrics(): void
    {
        $run = $this->importer(true)->import($this->write($this->results()));

        $this->assertSame('q_1c8e9b0d', $run->ftq_id);
        $this->assertSame(2, EvalTrial::query()->count());
        $this->assertSame('cap_stopped', EvalTrial::query()->where('task_id', 'music-harmony')->value('status'),
            'a stopped run stays cap_stopped even when the grader gave it a reward');
        $prism = EvalMetric::query()->where('arm', 'prism')->firstOrFail();
        $this->assertFalse($prism->neutral);
        $this->assertSame(-0.1, $prism->delta['delta']['usd_per_solved']);
        $this->assertTrue(EvalMetric::query()->where('arm', 'terminus-2')->firstOrFail()->neutral);
    }

    public function test_an_unverified_file_is_refused_before_anything_is_stored(): void
    {
        $path = $this->write($this->results());

        $this->expectException(EvalImportRefused::class);
        try {
            $this->importer(false, $seen)->import($path);
        } finally {
            $this->assertSame([realpath($path)], $seen);
            $this->assertSame(0, EvalRun::query()->count());
        }
    }

    public function test_the_same_approval_imports_once(): void
    {
        $importer = $this->importer(true);
        $path = $this->write($this->results());
        $first = $importer->import($path);
        $this->assertSame($first->id, $importer->import($path)->id, 'identical bytes are a no-op');

        $this->expectException(EvalImportRefused::class);
        $importer->import($this->write($this->results(['spent_usd' => 0.9])));
    }

    public function test_it_refuses_a_foreign_schema_an_unknown_status_and_a_bad_ftq_id(): void
    {
        foreach ([
            $this->results(['schema' => 'something-else/1']),
            $this->results(['ftq_id' => '../../etc']),
            $this->results(['trials' => [['benchmark' => 'tau2', 'arm' => 'prism', 'task_id' => 'x', 'status' => 'solved-ish']]]),
        ] as $bad) {
            try {
                $this->importer(true)->import($this->write($bad));
                $this->fail('a malformed results file was imported');
            } catch (EvalImportRefused) {
                $this->assertSame(0, EvalRun::query()->count());
            }
        }
    }

    public function test_the_verifier_pins_this_repository_and_the_bench_workflow(): void
    {
        $this->assertSame('Particle-Academy/prism-labs', GhAttestationVerifier::REPOSITORY);
        $this->assertSame('Particle-Academy/prism-labs/.github/workflows/harness-bench.yml', GhAttestationVerifier::SIGNER_WORKFLOW);
        $this->assertInstanceOf(GhAttestationVerifier::class, app(AttestationVerifier::class));
    }

    public function test_the_benchmarks_page_shows_the_latest_run_beside_its_cost(): void
    {
        $this->importer(true)->import($this->write($this->results()));

        // The routes register only in a local app, as in the real Lab; a fixture key, no real key is read.
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();

        $this->get('/lab/benchmarks')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Lab/Benchmarks')
                ->where('harnessBench.ftq_id', 'q_1c8e9b0d')
                ->where('harnessBench.rows.0.arm', 'prism')
                ->where('harnessBench.rows.0.usd_per_solved', 0.4)
                ->where('harnessBench.rows.0.shared_tasks', 2));
    }

    public function test_every_frozen_plan_prices_its_models_from_model_prices(): void
    {
        $prices = new ModelPrices;
        $plans = glob(base_path('evals/plans/*.json')) ?: [];
        foreach ($plans as $file) {
            $plan = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            foreach ($plan['prices'] as $provider => $models) {
                foreach ($models as $model => $entry) {
                    $quote = $prices->forModel($provider, $model);
                    $this->assertNotNull($quote, "{$provider}/{$model} has no Lab price");
                    $this->assertSame(self::harnessEntry($quote), self::comparable($entry), basename($file)." {$provider}/{$model}");
                }
            }
        }
        $this->assertTrue(true);
    }

    /** ModelPrices' rates in the harness price-entry shape the plan carries. */
    private static function harnessEntry(array $quote): array
    {
        $tier = static fn (array $rate): array => array_filter([
            'inputPerMTok' => (float) $rate['input'], 'outputPerMTok' => (float) $rate['output'],
            'cacheReadPerMTok' => isset($rate['cache_read']) ? (float) $rate['cache_read'] : null,
            'cacheWritePerMTok' => isset($rate['cache_write_5m']) ? (float) $rate['cache_write_5m']
                : (isset($rate['cache_write_30m']) ? (float) $rate['cache_write_30m'] : null),
        ], static fn ($v): bool => $v !== null);
        $entry = $tier($quote['rates'][0]);
        foreach (array_slice($quote['rates'], 1) as $rate) {
            preg_match('/Over ([\d,]+) input tokens/', (string) $rate['context'], $m);
            $entry['tiers'][] = ['aboveInputTokens' => (int) str_replace(',', '', $m[1] ?? '0'), ...$tier($rate)];
        }

        return self::comparable($entry);
    }

    private static function comparable(array $entry): array
    {
        unset($entry['toolOverheadTokens']);
        ksort($entry);
        if (isset($entry['tiers'])) {
            $entry['tiers'] = array_map(static function (array $t): array {
                $t = array_map(static fn ($v) => is_int($v) && ! is_bool($v) ? $v : (float) $v, $t);
                $t['aboveInputTokens'] = (int) $t['aboveInputTokens'];
                ksort($t);

                return $t;
            }, $entry['tiers']);
        }

        return array_map(static fn ($v) => is_array($v) ? $v : (float) $v, $entry);
    }
}
