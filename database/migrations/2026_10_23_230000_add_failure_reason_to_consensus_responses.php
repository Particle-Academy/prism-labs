<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consensus_responses', function (Blueprint $table): void {
            $table->text('failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consensus_responses', function (Blueprint $table): void {
            $table->dropColumn('failure_reason');
        });
    }
};
