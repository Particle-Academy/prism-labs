<?php

use App\Http\Controllers\Lab\AgentConversationController;
use App\Http\Controllers\Lab\BenchmarkController;
use App\Http\Controllers\Lab\CapabilityController;
use App\Http\Controllers\Lab\ChatController;
use App\Http\Controllers\Lab\CockpitController;
use App\Http\Controllers\Lab\ConsensusController;
use App\Http\Controllers\Lab\HumanPlusFixtureController;
use App\Http\Controllers\Lab\TeamController;
use App\Http\Controllers\Lab\TelemetryController;
use App\Http\Controllers\Lab\TestSuiteController;
use App\Http\Controllers\Lab\ThreadController;
use App\Http\Middleware\EnsurePrismLabIsLocal;
use Illuminate\Support\Facades\Route;

// Everything here is the Lab. There is no public surface to separate it from,
// which is the reason this application was split out of the docs site: the
// Lab holds provider credentials and runs real generations, and it shared a
// deployment with a site that serves the internet.
//
// The local-only guard and the environment check both stay anyway. This app
// is not deployed, and the guard is what keeps that true by construction
// rather than by intention.
Route::redirect('/', '/lab');

if (app()->environment('local')) {
    Route::middleware(EnsurePrismLabIsLocal::class)->group(function (): void {
        Route::get('/lab', CockpitController::class)->name('lab.cockpit');
        Route::inertia('/lab/diagnostics', 'Lab/Diagnostics')->name('lab.diagnostics');
        Route::get('/lab/consensus', [ConsensusController::class, 'show'])->name('lab.consensus');
        Route::post('/lab/consensus', [ConsensusController::class, 'store'])->middleware('throttle:6,1')->name('lab.consensus.store');
        Route::post('/lab/consensus/{run}/review', [ConsensusController::class, 'review'])->middleware('throttle:10,1')->name('lab.consensus.review');
        Route::inertia('/lab/evidence', 'Lab/Evidence')->name('lab.evidence');
        // The team board. The roster is fetched separately from the page render
        // because probing means one network call per addressable lane, and a
        // lane that is down would otherwise hold the whole page behind its
        // timeout. A dead agent should cost one card, not the board.
        Route::get('/lab/team', [TeamController::class, 'show'])->name('lab.team');
        Route::get('/lab/team/roster', [TeamController::class, 'roster'])->name('lab.team.roster');

        // The harness ports, exercised end to end in BOTH languages. Fetched
        // separately from the page for the same reason the roster is: two
        // network calls, and a down lane would hold the board behind a timeout.
        Route::get('/lab/team/harness', [TeamController::class, 'harness'])->name('lab.team.harness');

        // The six satellite ports, exercised the same way. A separate route
        // from the harness one on purpose: they are separate claims, and a
        // family that breaks should cost its own panel rather than both.
        Route::get('/lab/team/ecosystem', [TeamController::class, 'ecosystem'])->name('lab.team.ecosystem');

        // Asking Prism is slow — it delegates, and a teammate may reason for a
        // while — so it gets a looser throttle than the page routes.
        // Hands one 0L to the coding agent in this workspace, over Genie's MCP.
        // Throttled hard: the value of that channel is that a message arriving
        // there is worth reading, and that survives exactly as long as they stay
        // rare.
        Route::post('/lab/team/nudge', [TeamController::class, 'nudge'])
            ->middleware('throttle:6,1')
            ->name('lab.team.nudge');

        Route::post('/lab/team/ask', [TeamController::class, 'ask'])
            ->middleware('throttle:20,1')
            ->name('lab.team.ask');

        Route::get('/lab/chat', [ChatController::class, 'show'])->name('lab.chat');
        Route::post('/lab/chat', [ChatController::class, 'run'])->middleware('throttle:10,1')->name('lab.chat.run');
        Route::get('/lab/agent', [AgentConversationController::class, 'show'])->name('lab.agent.show');
        Route::post('/lab/agent', [AgentConversationController::class, 'send'])->middleware('throttle:20,1')->name('lab.agent.send');
        Route::get('/lab/capabilities', [CapabilityController::class, 'status'])->name('lab.capabilities');
        Route::get('/lab/human-plus-fixture', [HumanPlusFixtureController::class, 'show'])->name('lab.human-plus-fixture');
        Route::post('/lab/capabilities/browser', [CapabilityController::class, 'openBrowser'])->middleware('throttle:10,1')->name('lab.capabilities.browser.open');
        Route::delete('/lab/capabilities/browser', [CapabilityController::class, 'closeBrowser'])->middleware('throttle:10,1')->name('lab.capabilities.browser.close');
        Route::post('/lab/capabilities/human-plus', [CapabilityController::class, 'attachHumanPlus'])->middleware('throttle:10,1')->name('lab.capabilities.human-plus.attach');
        Route::delete('/lab/capabilities/human-plus', [CapabilityController::class, 'detachHumanPlus'])->middleware('throttle:10,1')->name('lab.capabilities.human-plus.detach');
        Route::get('/lab/tests', [TestSuiteController::class, 'show'])->name('lab.tests');
        Route::post('/lab/tests', [TestSuiteController::class, 'run'])->middleware('throttle:10,1')->name('lab.tests.run');
        Route::get('/lab/threads', [ThreadController::class, 'show'])->name('lab.threads');
        Route::get('/lab/telemetry', [TelemetryController::class, 'show'])->name('lab.telemetry');
        Route::get('/lab/benchmarks', [BenchmarkController::class, 'show'])->name('lab.benchmarks');
        Route::get('/lab/benchmarks/specs/{spec}', [BenchmarkController::class, 'specification'])->name('lab.benchmark-specs.show');
        Route::get('/lab/benchmarks/runs/{run}', [BenchmarkController::class, 'runRoom'])->name('lab.benchmark-runs.show');
        Route::get('/lab/benchmarks/runs/{run}/lanes/{lane}', [BenchmarkController::class, 'lane'])->name('lab.benchmark-lanes.show');
        Route::get('/lab/benchmarks/runs/{run}/lanes/{lane}/file', [BenchmarkController::class, 'laneFile'])->name('lab.benchmark-lanes.file');
        Route::get('/lab/benchmarks/runs/{run}/lanes/{lane}/media', [BenchmarkController::class, 'laneMedia'])->name('lab.benchmark-lanes.media');
        Route::post('/lab/benchmarks', [BenchmarkController::class, 'store'])->middleware('throttle:10,1')->name('lab.benchmarks.store');
        Route::post('/lab/benchmarks/{spec}/request-approval', [BenchmarkController::class, 'requestApproval'])->middleware('throttle:10,1')->name('lab.benchmarks.request-approval');
        Route::post('/lab/benchmarks/{spec}/approve', [BenchmarkController::class, 'approve'])->middleware('throttle:10,1')->name('lab.benchmarks.approve');
        Route::post('/lab/benchmarks/{spec}/launch', [BenchmarkController::class, 'launch'])->middleware('throttle:3,1')->name('lab.benchmarks.launch');
        Route::post('/lab/benchmarks/runs/{run}/stop', [BenchmarkController::class, 'stop'])
            ->middleware('throttle:6,1')
            ->name('lab.benchmark-runs.stop');
        Route::delete('/lab/benchmarks/runs/{run}', [BenchmarkController::class, 'destroy'])
            ->middleware('throttle:12,1')
            ->name('lab.benchmark-runs.destroy');
        Route::delete('/lab/benchmarks/runs', [BenchmarkController::class, 'clear'])
            ->middleware('throttle:6,1')
            ->name('lab.benchmark-runs.clear');
        Route::get('/lab/benchmarks/export', [BenchmarkController::class, 'export'])->name('lab.benchmarks.export');
    });
}
