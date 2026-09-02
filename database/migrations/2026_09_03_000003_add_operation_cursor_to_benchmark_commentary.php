<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commentary needs a second cursor, because the events live in two tables.
 *
 * `benchmark_lane_activities` carries the narrated milestones and
 * `lab_operations` carries the tool calls — and an agent deep in a build emits
 * the second for minutes without emitting the first. Reading activities alone
 * left the ticker silent through exactly the stretch worth calling, which is
 * the same mistake the lane heartbeat made before it was taught to read both.
 *
 * A timestamp rather than a key: `lab_operations.id` is a random UUID v4, so
 * "everything after this id" is meaningless there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_commentary', function (Blueprint $table): void {
            $table->timestamp('after_operation_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_commentary', function (Blueprint $table): void {
            $table->dropColumn('after_operation_at');
        });
    }
};
