<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Models\LabPaidWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

final class BenchmarkAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    private function approvedSpec(): BenchmarkSpec
    {
        $this->prepareLabAuthority();
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'],
            ['value' => json_encode(['anthropic:claude-sonnet-5', 'anthropic:claude-haiku-4-5-20251001'], JSON_THROW_ON_ERROR)]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        try {
            $designer = app(BenchmarkDesigner::class);
            $spec = $designer->draft('Owned benchmark '.BenchmarkSpec::query()->count(), 'research', 'standard',
                ['outcome' => 'Owned outcome'], ['quality' => 100], [
                    ['language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
                    ['language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'],
                ], ['cost' => 1]);

            return $designer->approve($designer->requestApproval($spec));
        } finally {
            auth('web')->logout();
        }
    }

    public function test_a_spec_approval_alone_cannot_launch_a_business_run(): void
    {
        $spec = $this->approvedSpec();
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(BenchmarkDesigner::class)->launch($spec);
        } finally {
            $fake->assertCallCount(0);
            $this->assertSame(0, BenchmarkRun::query()->count());
        }
    }

    public function test_the_exact_go_creates_one_run_and_binds_every_shuffled_lane(): void
    {
        $spec = $this->approvedSpec();
        $fake = Prism::fake([]);
        $run = $this->withApprovedLabRun('benchmarks.launch', [], fn () => app(BenchmarkDesigner::class)->launch($spec), $spec->id);
        $this->assertGuest('web');
        $this->assertNotNull($run->approved_run_id);
        $root = LabPaidWork::query()->findOrFail($run->paid_work_id);
        $this->assertSame('benchmark.run', $root->kind);
        $this->assertSame($run->approved_run_id, $root->intent_id);
        $this->assertCount(2, $root->payload['lanes']);
        foreach ($run->lanes as $lane) {
            $work = LabPaidWork::query()->findOrFail($lane->paid_work_id);
            $this->assertSame($root->id, $work->parent_id);
            $this->assertSame($root->intent_id, $work->intent_id);
            $this->assertSame($lane->id, $work->work_key);
            $this->assertSame($lane->model, $work->payload['model']);
            $this->assertNull($work->claimed_at, 'Dispatch must not pretend inference already happened.');
        }
        $fake->assertCallCount(0);
    }

    public function test_reusing_the_consumed_scope_cannot_create_a_second_business_run(): void
    {
        $spec = $this->approvedSpec();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withApprovedLabRun('benchmarks.launch', [], function () use ($spec): void {
                app(BenchmarkDesigner::class)->launch($spec);
                app(BenchmarkDesigner::class)->launch($spec);
            }, $spec->id);
        } finally {
            $this->assertSame(1, BenchmarkRun::query()->count());
            $this->assertSame(2, BenchmarkLane::query()->count());
        }
    }

    public function test_another_approved_spec_cannot_replace_the_reviewed_resource(): void
    {
        $first = $this->approvedSpec();
        $other = $this->approvedSpec();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withApprovedLabRun('benchmarks.launch', [], fn () => app(BenchmarkDesigner::class)->launch($other), $first->id);
        } finally {
            $this->assertSame(0, BenchmarkRun::query()->count());
        }
    }

    #[DataProvider('approvalFields')]
    public function test_a_legacy_or_relabelled_spec_approval_cannot_mint_run_authority(string $field, mixed $value): void
    {
        $spec = $this->approvedSpec();
        $spec->forceFill([$field => $value])->save();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->approvedLabRun('benchmarks.launch', [], $spec->id);
        } finally {
            $this->assertSame(0, BenchmarkRun::query()->count());
        }
    }

    public static function approvalFields(): array
    {
        return [['approved_by', null], ['approved_digest', null], ['approved_digest', str_repeat('f', 64)], ['approved_at', null], ['status', 'draft']];
    }

    public function test_launch_reloads_a_changed_database_spec_instead_of_a_stale_caller_model(): void
    {
        $spec = $this->approvedSpec();
        $this->expectException(ValidationException::class);
        try {
            $this->withApprovedLabRun('benchmarks.launch', [], function () use ($spec): void {
                BenchmarkSpec::query()->whereKey($spec->id)->update(['name' => 'changed after GO']);
                app(BenchmarkDesigner::class)->launch($spec);
            }, $spec->id);
        } finally {
            $this->assertSame(0, BenchmarkRun::query()->count());
        }
    }

    public function test_the_current_model_selection_cannot_change_after_go(): void
    {
        $spec = $this->approvedSpec();
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withApprovedLabRun('benchmarks.launch', [], function () use ($spec): void {
                DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'], ['value' => '[]']);
                app(BenchmarkDesigner::class)->launch($spec);
            }, $spec->id);
        } finally {
            $this->assertSame(0, BenchmarkRun::query()->count());
        }
    }

    public function test_a_root_work_record_cannot_substitute_a_different_lane_matrix(): void
    {
        $spec = $this->approvedSpec();
        $this->expectException(PaidRunNotAuthorised::class);
        $this->withApprovedLabRun('benchmarks.launch', [], fn () => app(PaidWorkRegistry::class)->bind('benchmark.run', (string) Str::uuid(),
            ['spec_id' => $spec->id, 'spec_digest' => $spec->digest, 'lanes' => [
                ['id' => (string) Str::uuid(), 'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'unreviewed'],
                ['id' => (string) Str::uuid(), 'language' => 'php', 'harness' => 'prism-harness', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'],
            ]]), $spec->id);
    }

    public function test_a_spec_cannot_mint_authority_for_a_model_outside_the_owner_selection(): void
    {
        $spec = $this->approvedSpec();
        DB::table('lab_settings')->where('key', 'benchmark.allowed_models')->update(['value' => '[]']);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->approvedLabRun('benchmarks.launch', [], $spec->id);
        } finally {
            $this->assertSame(0, BenchmarkRun::query()->count());
        }
    }
}
