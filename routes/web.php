<?php

use App\Http\Controllers\Lab\BenchmarkController;
use App\Http\Controllers\Lab\ChatController;
use App\Http\Controllers\Lab\TeamController;
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
Route::redirect('/', '/lab/team');

if (app()->environment('local')) {
    Route::middleware([EnsurePrismLabIsLocal::class, 'throttle:10,1'])->group(function (): void {
        // The team board. The roster is fetched separately from the page render
        // because probing means one network call per addressable lane, and a
        // lane that is down would otherwise hold the whole page behind its
        // timeout. A dead agent should cost one card, not the board.
        Route::get('/lab/team', [TeamController::class, 'show'])->name('lab.team');
        Route::get('/lab/team/roster', [TeamController::class, 'roster'])->name('lab.team.roster');

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
        Route::post('/lab/chat', [ChatController::class, 'run'])->name('lab.chat.run');
        Route::get('/lab/tests', [TestSuiteController::class, 'show'])->name('lab.tests');
        Route::post('/lab/tests', [TestSuiteController::class, 'run'])->name('lab.tests.run');
        Route::get('/lab/threads', [ThreadController::class, 'show'])->name('lab.threads');
        Route::get('/lab/benchmarks', [BenchmarkController::class, 'show'])->name('lab.benchmarks');
        Route::get('/lab/benchmarks/export', [BenchmarkController::class, 'export'])->name('lab.benchmarks.export');
    });
}
