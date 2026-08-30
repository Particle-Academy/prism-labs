<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_specs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('revision');
            $table->string('digest', 64)->unique();
            $table->string('status')->default('draft');
            $table->string('name');
            $table->string('archetype');
            $table->string('surface_mode')->default('standard');
            $table->json('specification');
            $table->json('rubric');
            $table->json('lane_matrix');
            $table->json('budgets');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['name', 'revision']);
        });

        Schema::create('benchmark_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('benchmark_spec_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('queued');
            $table->json('randomized_order');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('benchmark_lanes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('benchmark_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->string('language');
            $table->string('harness');
            $table->string('provider');
            $table->string('model');
            $table->string('status')->default('queued');
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedInteger('turns')->nullable();
            $table->unsignedInteger('corrections')->nullable();
            $table->decimal('cost', 18, 8)->nullable();
            $table->string('cost_source')->default('unpriced');
            $table->decimal('score', 8, 4)->nullable();
            $table->json('proof')->nullable();
            $table->string('learning_ref')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['benchmark_run_id', 'ordinal']);
        });

        Schema::create('benchmark_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('benchmark_lane_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('digest', 64);
            $table->json('payload');
            $table->timestamps();
            $table->index(['benchmark_lane_id', 'kind']);
        });

        Schema::create('consensus_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('question');
            $table->string('evidence_digest', 64);
            $table->string('status')->default('collecting');
            $table->longText('synthesis')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consensus_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('consensus_run_id')->constrained()->cascadeOnDelete();
            $table->string('agent');
            $table->string('language');
            $table->string('status');
            $table->longText('answer')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('dissent')->nullable();
            $table->timestamps();
            $table->unique(['consensus_run_id', 'agent']);
        });

        Schema::create('lab_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->uuid('benchmark_run_id')->nullable()->index();
            $table->uuid('benchmark_lane_id')->nullable()->index();
            $table->uuid('consensus_run_id')->nullable()->index();
            $table->string('harness_session')->nullable()->index();
            $table->string('trace_id')->nullable()->index();
            $table->string('kind')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('language')->nullable();
            $table->string('status')->default('running')->index();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_tokens')->nullable();
            $table->unsignedBigInteger('cache_read_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->decimal('cost', 18, 8)->nullable();
            $table->string('cost_source')->default('unpriced');
            $table->string('failure_class')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_operations');
        Schema::dropIfExists('consensus_responses');
        Schema::dropIfExists('consensus_runs');
        Schema::dropIfExists('benchmark_receipts');
        Schema::dropIfExists('benchmark_lanes');
        Schema::dropIfExists('benchmark_runs');
        Schema::dropIfExists('benchmark_specs');
    }
};
