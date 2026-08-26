<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learnings', function (Blueprint $table): void {
            $table->id();

            // 0L-0001. Unique because the id is how a learning is cited, and a
            // citation that resolves to two things is not a citation.
            $table->string('ref')->unique();

            $table->string('title');
            $table->string('filed_by');
            $table->string('severity')->default('info');

            // Which languages the learning is about — not who filed it.
            $table->json('languages');

            $table->text('what_was_learned');
            $table->text('evidence');

            // The load-bearing one. Not nullable: a finding that cannot say
            // why it matters is a log line, and log lines do not survive being
            // read later by someone deciding what to work on.
            $table->text('why_it_matters');

            $table->text('what_should_change')->nullable();

            // Where the authoritative markdown landed. The row is the feed;
            // the file is the record.
            $table->string('path');

            $table->timestamps();

            $table->index(['severity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learnings');
    }
};
