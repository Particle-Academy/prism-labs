<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Models\BenchmarkSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class FrozenSpecTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): BenchmarkSpec
    {
        return app(BenchmarkDesigner::class)->draft('Frozen transition', 'research', 'standard',
            ['outcome' => 'An unchanged brief'], [], [
                ['language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'synthetic-model'],
            ], ['cost' => 1]);
    }

    public function test_a_stale_draft_cannot_demote_an_owner_approved_revision(): void
    {
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        $designer = app(BenchmarkDesigner::class);
        $stale = $this->draft();
        $waiting = $designer->requestApproval(BenchmarkSpec::query()->findOrFail($stale->id));
        $approved = $designer->approve($waiting);
        $this->expectException(LogicException::class);
        try {
            $designer->requestApproval($stale);
        } finally {
            $current = $approved->fresh();
            $this->assertSame('approved', $current->status);
            $this->assertSame($owner->id, $current->approved_by);
            $this->assertSame($approved->digest, $current->approved_digest);
        }
    }

    public function test_tampered_current_data_cannot_enter_the_approval_queue_from_a_stale_copy(): void
    {
        $stale = $this->draft();
        DB::table('benchmark_specs')->where('id', $stale->id)->update(['budgets' => '{"cost":2}']);
        $this->expectException(ValidationException::class);
        try {
            app(BenchmarkDesigner::class)->requestApproval($stale);
        } finally {
            $this->assertSame('draft', $stale->fresh()->status);
            $this->assertNull($stale->fresh()->approved_digest);
        }
    }
}
