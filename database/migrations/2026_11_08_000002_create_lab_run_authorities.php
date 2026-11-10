<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_authority_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('allocated_microusd')->default(0);
            $table->timestamps();
        });
        Schema::create('lab_run_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('authority_session_id')->constrained('lab_authority_sessions')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('resource', 200);
            $table->json('payload');
            $table->unsignedBigInteger('cap_microusd');
            $table->string('digest', 64);
            $table->string('status', 24)->default('draft');
            $table->timestamp('expires_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('approved_digest', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('go_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('go_digest', 64)->nullable();
            $table->timestamp('go_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('acp_host_claimed_at')->nullable();
            $table->timestamp('acp_prompt_claimed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('lab_paid_works', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('intent_id')->constrained('lab_run_intents')->restrictOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('lab_paid_works')->restrictOnDelete();
            $table->string('kind', 64);
            $table->string('work_key', 128);
            $table->json('payload');
            $table->string('payload_digest', 64);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'work_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_paid_works');
        Schema::dropIfExists('lab_run_intents');
        Schema::dropIfExists('lab_authority_sessions');
    }
};
