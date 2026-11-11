<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['overseer_turns', 'acp_turns', 'consensus_runs', 'benchmark_runs'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignUuid('approved_run_id')->nullable()->unique()->constrained('lab_run_intents')->restrictOnDelete();
                $table->foreignUuid('paid_work_id')->nullable()->constrained('lab_paid_works')->restrictOnDelete();
            });
        }
        Schema::table('benchmark_lanes', function (Blueprint $table): void {
            $table->foreignUuid('paid_work_id')->nullable()->constrained('lab_paid_works')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_lanes', fn (Blueprint $table) => $table->dropConstrainedForeignId('paid_work_id'));
        foreach (['overseer_turns', 'acp_turns', 'consensus_runs', 'benchmark_runs'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('paid_work_id');
                $table->dropUnique(['approved_run_id']);
                $table->dropConstrainedForeignId('approved_run_id');
            });
        }
    }
};
