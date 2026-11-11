<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_lanes', function (Blueprint $table): void {
            $table->json('frozen_test_lane')->nullable();
        });
        Schema::create('lab_call_reservations', function (Blueprint $table): void {
            $table->string('call_id', 32)->primary();
            $table->foreignUuid('intent_id')->constrained('lab_run_intents')->restrictOnDelete();
            $table->foreignUuid('paid_work_id')->constrained('lab_paid_works')->restrictOnDelete();
            $table->string('work_digest', 64);
            $table->string('root_run_id', 128);
            $table->string('provider', 80);
            $table->string('model', 200);
            $table->string('kind', 20);
            $table->unsignedBigInteger('maximum_microusd');
            $table->unsignedBigInteger('charged_microusd')->nullable();
            $table->boolean('usage_known')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['intent_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_call_reservations');
        Schema::table('benchmark_lanes', fn (Blueprint $table) => $table->dropColumn('frozen_test_lane'));
    }
};
