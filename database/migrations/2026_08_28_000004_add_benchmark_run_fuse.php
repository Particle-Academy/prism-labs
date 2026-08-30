<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_runs', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_runs', fn (Blueprint $table) => $table->dropColumn(['cancelled_at', 'cancel_reason']));
    }
};
