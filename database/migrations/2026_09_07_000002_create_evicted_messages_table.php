<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Lab keeps what leaves an agent's context window.
 *
 * A table rather than `prism-memory`, deliberately, and the harness contract
 * names this exact option: "An application that wants a log file, an audit
 * table, or a queue writes twelve lines instead."
 *
 * The point of proving it this way is that it removes embeddings from the
 * experiment. If a compacted agent can answer from an evicted turn using
 * nothing but a `LIKE`, the mechanism — evict, store, recall, answer — is
 * sound, and any later argument about retrieval quality is about retrieval
 * quality rather than about whether the plumbing works. Starting with the
 * semantic version would have confounded the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evicted_messages', function (Blueprint $table): void {
            $table->id();
            // The thread this left, so a recall searches the conversation that
            // lost it rather than every conversation the Lab has ever held.
            $table->string('scope')->index();
            $table->string('role', 32);
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evicted_messages');
    }
};
