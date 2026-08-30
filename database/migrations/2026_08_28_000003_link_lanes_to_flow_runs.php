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
            $table->unsignedBigInteger('workflow_run_id')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_lanes', fn (Blueprint $table) => $table->dropColumn('workflow_run_id'));
    }
};
