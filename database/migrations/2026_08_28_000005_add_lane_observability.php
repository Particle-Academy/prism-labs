<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_lanes', function (Blueprint $table): void {
            $table->string('workspace_path')->nullable()->after('workflow_run_id');
        });

        Schema::create('benchmark_lane_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('benchmark_lane_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('level')->default('info');
            $table->string('summary', 500);
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->index(['benchmark_lane_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_lane_activities');
        Schema::table('benchmark_lanes', fn (Blueprint $table) => $table->dropColumn('workspace_path'));
    }
};
