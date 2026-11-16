<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * G-291: recorded usage against provider billing.
 *
 * provider_usage_records holds ONE ROW PER GENERATION, keyed by Prism's
 * telemetry trace id, so a repeated or doubled failure event cannot count the
 * same call twice. `call_count` is the number of provider requests inside that
 * generation (one per step), which is what the billed side's request counts
 * measure. Tokens follow Prism's usage contract: `input_tokens` is UNCACHED
 * input; cached and cache-write input are separate counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->string('trace_id', 64)->unique();
            $table->string('provider', 80);
            $table->string('model', 200);
            $table->date('day');
            $table->string('outcome', 20);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            // Null when Harness has no trusted rate for the model: unpriced,
            // never free.
            $table->decimal('harness_cost_usd', 18, 8)->nullable();
            $table->unsignedInteger('call_count')->default(1);
            $table->timestamps();
            $table->index(['provider', 'day', 'model']);
        });

        Schema::create('billing_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 80);
            $table->date('day');
            // '*' is the provider's whole day.
            $table->string('model', 200);
            $table->string('metric', 10);
            // ok | flagged | not_configured | unknown
            $table->string('status', 20);
            $table->string('reason', 80)->nullable();
            $table->decimal('recorded', 24, 8)->nullable();
            $table->decimal('billed', 24, 8)->nullable();
            $table->decimal('delta', 24, 8)->nullable();
            $table->decimal('delta_percent', 12, 4)->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->unique(['provider', 'day', 'model', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reconciliations');
        Schema::dropIfExists('provider_usage_records');
    }
};
