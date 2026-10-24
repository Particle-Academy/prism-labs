<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per turn put to the ACP agent (/lab/acp), so the turn outlives the request that asked for it -- the same
 * reason overseer_turns exists. The updates the agent sent are kept on the row as they arrive, and the page polls
 * them: what a client of the agent actually received, in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acp_turns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // queued -> running -> finished | failed
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('model')->nullable();
            // The CLI's own session id this turn resumed (session/load), and the one it reported: the next turn's.
            $table->string('resume_session_id')->nullable();
            $table->string('cli_session_id')->nullable();
            $table->string('stop_reason')->nullable();
            $table->text('error')->nullable();
            // Every session/update the agent sent, in order; and what initialize and session/new answered.
            $table->json('updates')->nullable();
            $table->json('handshake')->nullable();
            // The agent host's process tree, and what the stop found of it.
            $table->json('process')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acp_turns');
    }
};
