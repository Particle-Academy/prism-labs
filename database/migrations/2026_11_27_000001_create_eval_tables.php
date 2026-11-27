<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-393: imported harness-benchmark results. Additive: three new tables, nothing else changes.
 *
 * A row here comes only from `lab:import-eval`, which refuses a results file
 * whose GitHub attestation does not verify against harness-bench.yml. The Lab
 * never runs a benchmark and never holds the bench keys; it reads what the
 * signed workflow produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('ftq_id', 80)->unique();
            $table->string('plan_path', 255);
            $table->string('plan_digest', 80);
            $table->decimal('cap_usd', 12, 4);
            $table->decimal('spent_usd', 14, 8);
            $table->string('model', 120);
            $table->string('reasoning_effort', 32);
            $table->string('harness_version', 80);
            $table->string('workflow_run_id', 40)->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->char('results_sha256', 64);
            $table->timestamp('attestation_verified_at');
            $table->timestamps();
        });

        Schema::create('eval_trials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('eval_run_id')->constrained('eval_runs')->cascadeOnDelete();
            $table->string('benchmark', 32);
            $table->string('arm', 40);
            $table->string('task_id', 160);
            $table->unsignedInteger('trial');
            $table->string('status', 16);
            $table->double('reward')->nullable();
            $table->decimal('cost_usd', 14, 8);
            $table->unsignedBigInteger('fresh_input_tokens');
            $table->unsignedBigInteger('cache_write_tokens');
            $table->unsignedBigInteger('cache_read_tokens');
            $table->unsignedBigInteger('output_tokens');
            $table->unsignedInteger('steps');
            $table->double('wall_seconds')->nullable();
            $table->timestamps();
            $table->unique(['eval_run_id', 'benchmark', 'arm', 'task_id', 'trial']);
        });

        Schema::create('eval_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('eval_run_id')->constrained('eval_runs')->cascadeOnDelete();
            $table->string('benchmark', 32);
            $table->string('arm', 40);
            $table->boolean('neutral');
            $table->json('summary');
            $table->json('delta')->nullable();
            $table->timestamps();
            $table->unique(['eval_run_id', 'benchmark', 'arm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_metrics');
        Schema::dropIfExists('eval_trials');
        Schema::dropIfExists('eval_runs');
    }
};
