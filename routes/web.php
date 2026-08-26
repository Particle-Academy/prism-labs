<?php

use App\Http\Controllers\Lab\BenchmarkController;
use App\Http\Controllers\Lab\ChatController;
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
Route::redirect('/', '/lab/chat');

if (app()->environment('local')) {
    Route::middleware([EnsurePrismLabIsLocal::class, 'throttle:10,1'])->group(function (): void {
        Route::get('/lab/chat', [ChatController::class, 'show'])->name('lab.chat');
        Route::post('/lab/chat', [ChatController::class, 'run'])->name('lab.chat.run');
        Route::get('/lab/tests', [TestSuiteController::class, 'show'])->name('lab.tests');
        Route::post('/lab/tests', [TestSuiteController::class, 'run'])->name('lab.tests.run');
        Route::get('/lab/threads', [ThreadController::class, 'show'])->name('lab.threads');
        Route::get('/lab/benchmarks', [BenchmarkController::class, 'show'])->name('lab.benchmarks');
        Route::get('/lab/benchmarks/export', [BenchmarkController::class, 'export'])->name('lab.benchmarks.export');
    });
}
