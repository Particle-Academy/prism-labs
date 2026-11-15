<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('benchmark_specs', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('approved_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('benchmark_specs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_digest');
        });
    }
};
