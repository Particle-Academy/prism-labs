<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a finished run was settled at its actual cost (RunAuthority::settle),
 * and what that cost was. Additive and nullable: an intent that is never
 * settled leaves both empty and keeps its whole cap allocated, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_run_intents', function (Blueprint $table): void {
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('settled_microusd')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lab_run_intents', function (Blueprint $table): void {
            $table->dropColumn(['settled_at', 'settled_microusd']);
        });
    }
};
