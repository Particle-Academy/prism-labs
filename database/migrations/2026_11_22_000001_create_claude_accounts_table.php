<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claude accounts added at /lab/accounts. Additive: a new table, nothing else changes.
 *
 * A row is a label, the CLI configuration directory the Lab made for it, and whether it is the account the Lab's
 * Claude launches use. No credential, email or token is ever stored here: the CLI keeps its own sign-in in that
 * directory, and the page asks the CLI each time. No active row means the CLI's default account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 32)->unique();
            $table->string('path', 1024);
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_accounts');
    }
};
