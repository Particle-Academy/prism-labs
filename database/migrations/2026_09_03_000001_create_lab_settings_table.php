<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator choices that outlive a request and belong to nobody in particular.
 *
 * A key/value row rather than a column per setting: these are the Lab's own
 * knobs, they arrive one at a time, and a migration per knob is friction that
 * ends with the knob living in a config file where an operator cannot reach it
 * from the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_settings');
    }
};
