<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conformance_runs', function (Blueprint $table): void {
            $table->id();

            // The corpus a run was measured against. Two runs are only
            // comparable when these match — the digest is the whole point of
            // the corpus being versioned, and comparing across digests would
            // report drift that is really just a different question being asked.
            $table->string('corpus_version');
            $table->string('corpus_digest');

            $table->timestamps();

            $table->index('corpus_digest');
        });

        Schema::create('conformance_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conformance_run_id')->constrained()->cascadeOnDelete();

            $table->string('language');
            $table->string('suite');
            $table->string('case_id');
            $table->string('status');

            // Why a case was skipped. A skip without one is indistinguishable
            // from a case nobody got round to, and the reason is usually the
            // most interesting thing in the run — "JavaScript's number type
            // cannot represent 9007199254740993" is a real answer.
            $table->text('reason')->nullable();

            // Per-case rather than per-total, deliberately. Two languages can
            // report identical totals while disagreeing on which cases pass —
            // that is exactly what happened the first time this ran, and a
            // totals-only record would have shown both green.
            $table->unique(['conformance_run_id', 'language', 'suite', 'case_id']);
            $table->index(['conformance_run_id', 'suite', 'case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conformance_results');
        Schema::dropIfExists('conformance_runs');
    }
};
