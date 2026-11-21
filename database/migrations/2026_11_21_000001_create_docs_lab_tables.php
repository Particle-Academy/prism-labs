<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * G-316 phase B: the docs-agent lab's records. Additive only: three new tables,
 * nothing existing is altered. Runs on the real Lab database only by a later,
 * deliberate `migrate`, never as part of a test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docs_lab_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('lane', 16);
            $table->string('selection', 16);
            $table->string('status', 16);
            $table->boolean('passed')->nullable();
            $table->json('agent');
            $table->string('provider', 80)->nullable();
            $table->string('model', 200)->nullable();
            $table->string('judge_model', 200)->nullable();
            $table->foreignUuid('intent_id')->nullable()->constrained('lab_run_intents')->restrictOnDelete();
            $table->unsignedBigInteger('cost_microusd')->nullable();
            $table->string('error', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['lane', 'started_at']);
        });

        Schema::create('docs_lab_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('docs_lab_runs')->cascadeOnDelete();
            $table->string('scenario', 8);
            $table->unsignedInteger('scenario_version');
            $table->string('scenario_digest', 64);
            $table->string('outcome', 16)->nullable();
            $table->text('message')->nullable();
            $table->boolean('structural_passed');
            $table->string('judge_verdict', 8)->nullable();
            $table->text('judge_reason')->nullable();
            $table->boolean('passed');
            $table->json('checks');
            $table->json('calls');
            $table->unsignedInteger('model_calls');
            $table->string('error', 120)->nullable();
            $table->json('artefacts');
            $table->timestamps();
            $table->unique(['run_id', 'scenario']);
        });

        Schema::create('docs_lab_certificates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('sequence')->unique();
            $table->string('package', 120);
            $table->string('version', 40);
            $table->string('source_digest', 64);
            $table->json('body');
            $table->string('hash', 64)->unique();
            $table->string('previous_hash', 64)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docs_lab_certificates');
        Schema::dropIfExists('docs_lab_results');
        Schema::dropIfExists('docs_lab_runs');
    }
};
