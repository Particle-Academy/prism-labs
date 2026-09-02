<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-dimension breakdown behind a lane's score.
 *
 * `benchmark_lanes.score` holds one number, and one number is not a score
 * anybody can argue with. A rubric has dimensions with weights, so the record
 * has to say what each dimension scored, what it weighed, and WHY — otherwise
 * the Lab has replaced "not scored" with an unaccountable figure, which is
 * worse than the gap it replaced.
 *
 * `cited_receipt` is the load-bearing column. A judgement that names no
 * evidence is an opinion, and this Lab's whole claim is that it scores only
 * evidence-backed receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_scores', function (Blueprint $table): void {
            $table->id();
            $table->string('benchmark_lane_id')->index();
            $table->string('dimension');
            $table->decimal('weight', 6, 4);
            $table->decimal('score', 6, 2);
            $table->text('justification');
            $table->string('cited_receipt')->nullable();
            $table->timestamps();

            $table->unique(['benchmark_lane_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_scores');
    }
};
