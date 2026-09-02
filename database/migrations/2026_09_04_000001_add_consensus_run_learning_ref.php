<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every terminal consensus run files a 0L, and this is where it is recorded.
 *
 * Benchmark runs got this column first; consensus runs filed NOTHING at all,
 * which made them the larger gap of the two. A consensus run spends a real
 * call to every addressable language agent, and the runs worth writing down
 * are precisely the ones that produced no usable answer — an agent that was
 * down, or two that flatly disagreed. Those left no trace whatsoever.
 *
 * `learning_ref` is also the idempotency guard, exactly as it is on
 * `benchmark_runs`: the recorder is reachable from three paths (collection
 * that answered nothing, human review, abandonment) and without somewhere to
 * record "already filed" a run touched twice would file two learnings about
 * itself.
 *
 * `abandoned_at` / `abandon_reason` exist because "nobody is ever going to
 * review this" was previously not expressible. A run sat in `awaiting_review`
 * forever, which is indistinguishable from one a human is about to open — so
 * the surface could not tell the two apart, and neither could the recorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consensus_runs', function (Blueprint $table): void {
            $table->string('learning_ref')->nullable()->after('pushed_at');
            $table->timestamp('abandoned_at')->nullable()->after('learning_ref');
            $table->string('abandon_reason', 500)->nullable()->after('abandoned_at');
        });
    }

    public function down(): void
    {
        Schema::table('consensus_runs', function (Blueprint $table): void {
            $table->dropColumn(['learning_ref', 'abandoned_at', 'abandon_reason']);
        });
    }
};
