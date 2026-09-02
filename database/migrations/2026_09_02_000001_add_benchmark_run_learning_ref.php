<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every terminal benchmark run files a 0L, and this is where it is recorded.
 *
 * Lanes already carried a `learning_ref`; runs did not, so a run whose lanes
 * all failed had nowhere to point — which is exactly the run most worth
 * writing down. The column is also the idempotency guard: reconcile is called
 * once per finishing lane, so without somewhere to record "already filed" a
 * three-lane run would file three learnings about itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_runs', function (Blueprint $table): void {
            $table->string('learning_ref')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_runs', function (Blueprint $table): void {
            $table->dropColumn('learning_ref');
        });
    }
};
