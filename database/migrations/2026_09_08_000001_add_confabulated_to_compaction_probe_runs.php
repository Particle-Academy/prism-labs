<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the arm without recall invented a reason rather than saying it could
 * not find one.
 *
 * A column rather than a line in the verdict string, because it is EVIDENCE and
 * this table's first migration already argues the case: a row saying only
 * "survivable" is worth nothing later without the numbers that show what was
 * exercised. This is the one the summary-loss probe exists to surface — an
 * agent that degrades to "I cannot find it" is behaving correctly under loss,
 * and one that supplies a confident wrong answer is the failure the eviction
 * and recall layer is for.
 *
 * Defaulted false so every existing row keeps its meaning: the reservation and
 * recall probes never measured this, and false reads as "not observed" rather
 * than as "observed and clean" for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compaction_probe_runs', function (Blueprint $table): void {
            $table->boolean('confabulated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('compaction_probe_runs', function (Blueprint $table): void {
            $table->dropColumn('confabulated');
        });
    }
};
