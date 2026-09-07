<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recall probe shares the compaction probe's table.
 *
 * Two probes, one history, because they answer two halves of one question --
 * does a guarantee survive compaction, and does the detail survive it. Split
 * across two tables they would be two pages nobody cross-reads.
 *
 * `probe` names which one wrote the row. Defaulted to the reservation probe so
 * existing rows keep their meaning rather than becoming ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compaction_probe_runs', function (Blueprint $table): void {
            $table->string('probe')->default('reservation')->index();
            $table->boolean('looked')->default(false);
            $table->boolean('correct')->default(false);
            $table->boolean('fact_left_window')->default(false);
            $table->text('answer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('compaction_probe_runs', function (Blueprint $table): void {
            $table->dropColumn(['probe', 'looked', 'correct', 'fact_left_window', 'answer']);
        });
    }
};
