<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit for an owner GO recorded from a Genie ForceTheQuestion answer
 * (`lab:approve-from-ftq`): which channel the consent came through, the
 * question it answered, and the answer text. Additive and nullable; a page
 * approval leaves all three empty. The unique index makes one question buy
 * at most one GO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_run_intents', function (Blueprint $table): void {
            $table->string('approved_via', 40)->nullable();
            $table->string('owner_question_id', 64)->nullable()->unique();
            $table->text('owner_answer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lab_run_intents', function (Blueprint $table): void {
            $table->dropUnique(['owner_question_id']);
            $table->dropColumn(['approved_via', 'owner_question_id', 'owner_answer']);
        });
    }
};
