<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Overseer turn, so the answer outlives the request that asked for it.
 *
 * The turn used to run INSIDE the web request. plabs is served by
 * `php artisan serve`, which is single-threaded on Windows — measured at 629ms
 * for one request and 1726ms for three concurrent, strictly serialised — so a
 * turn that fans out to `conformance_<lang>` or `ask_<lang>` held the only
 * worker for minutes. Every other request got nothing, the browser reported
 * "Unexpected end of JSON input" because the body was empty, and the site
 * stayed wedged until it was restarted.
 *
 * The row is what lets the request return immediately: the job writes the
 * answer here, and the panel polls for it. A table rather than the cache
 * because a turn that took four minutes and then lost its answer to an evicted
 * key is worse than one that failed outright, and because the operator can see
 * what the agent was asked after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overseer_turns', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // queued -> running -> answered | failed. Kept as a string rather
            // than an enum so a new state is a deploy rather than a migration.
            $table->string('status')->index();
            $table->text('prompt');
            $table->text('answer')->nullable();

            // The failure the caller is shown. Separate from the logged
            // exception on purpose: this is written to be read by a person in a
            // chat panel, and a stack trace there is noise that also leaks more
            // than it should.
            $table->text('error')->nullable();
            $table->string('run_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overseer_turns');
    }
};
