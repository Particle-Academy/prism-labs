<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A learning is OPEN until somebody acts on it.
 *
 * Filing a 0L was the end of the loop: the Lab wrote it to disk and to the
 * database and nothing ever asked whether anyone had done something about it.
 * A backlog nobody can see the shape of is indistinguishable from an archive,
 * and the difference between the two is the whole point of filing them.
 *
 * `sent_at` and `acted_at` are deliberately separate. Handing a learning to an
 * agent is not the same as the learning being dealt with — conflating them
 * would let a send close the item and lose exactly the ones that were passed on
 * and then dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learnings', function (Blueprint $table): void {
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('acted_at')->nullable()->index();
            $table->text('acted_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learnings', function (Blueprint $table): void {
            $table->dropColumn(['sent_at', 'acted_at', 'acted_note']);
        });
    }
};
