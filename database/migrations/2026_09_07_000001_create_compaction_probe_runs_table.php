<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every compaction probe run, kept.
 *
 * The evidence columns are stored beside the verdict on purpose. A row saying
 * only "held" is worth nothing later: whether the guarantee was actually
 * exercised depends on `attempts` and `tool_uses_cleared`, and a run with zero
 * of either is a run that proved nothing however green it reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compaction_probe_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('verdict')->index();
            $table->boolean('reserved');
            $table->string('model');
            $table->unsignedInteger('steps')->default(0);
            $table->unsignedInteger('ledger_reads')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('executions')->default(0);
            $table->unsignedInteger('tool_uses_cleared')->default(0);
            $table->unsignedInteger('denials')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('failure')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compaction_probe_runs');
    }
};
