<?php

use App\Lab\BenchmarkStore;
use App\Lab\PrismTestRegistry;
use App\Lab\PrismTestRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Reconcile contribution XP daily as a safety net for webhook deliveries that
// were missed or failed. Idempotent (dedup keys) so it can never double-count.
// Requires the scheduler cron (`php artisan schedule:run`) and GITHUB_TOKEN.
Schedule::command('gamification:backfill')->dailyAt('05:00')->withoutOverlapping();

Artisan::command('lab:test {cases?* : Case IDs; omit to run all non-costly cases} {--include-costly}', function (PrismTestRegistry $registry, PrismTestRunner $runner, BenchmarkStore $benchmarks): int {
    abort_unless(app()->environment('local'), 404);
    $requested = collect($this->argument('cases'));
    $cases = $requested->isEmpty() ? $registry->all() : $requested->map(fn (string $id) => $registry->find($id));
    $cases = $cases->filter(fn ($case) => $case !== null && ($this->option('include-costly') || ! $case->costly));

    if ($cases->isEmpty()) {
        $this->error('No matching test cases.');

        return self::FAILURE;
    }

    $failed = false;
    $results = [];
    foreach ($cases as $case) {
        $this->line("Running {$case->id}...");
        $result = $runner->run($case);
        $results[] = $result;
        $failed = $failed || ! $result['passed'];
        $this->{$result['passed'] ? 'info' : 'error'}(($result['passed'] ? 'PASS' : 'FAIL')." {$case->id} ({$result['latency_ms']} ms)".($result['error'] ? ": {$result['error']}" : ''));
    }

    // Feed the benchmark history so /lab/benchmarks can compare over time.
    $benchmarks->record($results);

    return $failed ? self::FAILURE : self::SUCCESS;
})->purpose('Run real Prism provider×feature telemetry checks');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
