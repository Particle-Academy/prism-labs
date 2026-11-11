<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\FrozenTestDefinition;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ApprovesLabRuns;
use Tests\Concerns\FrozenTestFixture;
use Tests\TestCase;

final class RsiTestRevisionTest extends TestCase
{
    use ApprovesLabRuns;
    use FrozenTestFixture;
    use RefreshDatabase;

    private function draft(): BenchmarkSpec
    {
        return app(BenchmarkDesigner::class)->draftTest('Harness acceptance test', FrozenTestDefinition::fromArray(self::definitionInput()));
    }

    private function approve(BenchmarkSpec $draft): BenchmarkSpec
    {
        $this->authorityFixtureOwner ??= User::factory()->create();
        config(['lab_authority.owner_user_id' => $this->authorityFixtureOwner->id]);
        $this->actingAs($this->authorityFixtureOwner, 'web');
        $designer = app(BenchmarkDesigner::class);

        return $designer->approve($designer->requestApproval($draft));
    }

    public function test_free_persistence_and_owner_approval_freeze_the_entire_contract_without_buying_work(): void
    {
        $fake = Prism::fake([]);
        $draft = $this->draft();
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->approved_digest);
        $approved = $this->approve($draft);
        $this->assertSame('approved', $approved->status);
        $this->assertSame($this->authorityFixtureOwner->id, $approved->approved_by);
        $this->assertSame($approved->digest, $approved->approved_digest);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame(self::definitionInput()['lanes'], $approved->fresh()->lane_matrix);
        $this->assertSame([], $approved->rubric);
        foreach ([LabRunIntent::class, LabPaidWork::class, BenchmarkRun::class, BenchmarkLane::class] as $model) {
            $this->assertSame(0, $model::query()->count());
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $fake->assertCallCount(0);
    }

    public function test_edits_append_unapproved_revisions_and_preserve_the_old_approval_and_inputs(): void
    {
        $approved = $this->approve($this->draft());
        $original = $approved->fresh()->getRawOriginal();
        $input = self::definitionInput();
        $input['instruments'][0]['version'] = '1.1.0';
        $input['instruments'][0]['sha256'] = str_repeat('f', 64);
        $next = app(BenchmarkDesigner::class)->reviseTest($approved, FrozenTestDefinition::fromArray($input));
        $third = app(BenchmarkDesigner::class)->reviseTest($approved, FrozenTestDefinition::fromArray($input));
        $this->assertNotSame($approved->id, $next->id);
        $this->assertNotSame($approved->digest, $next->digest);
        $this->assertSame(2, $next->revision);
        $this->assertSame(3, $third->revision);
        $this->assertSame('draft', $next->status);
        $this->assertSame(['id' => $approved->id, 'digest' => $approved->digest], $next->specification['previous_revision']);
        $this->assertNull($next->approved_by);
        $this->assertNull($next->approved_at);
        $this->assertNull($next->approved_digest);
        $this->assertSame($original, $approved->fresh()->getRawOriginal());
        $this->assertSame(0, LabRunIntent::query()->count());
    }

    public function test_object_key_reordering_does_not_invalidate_the_actual_stored_frozen_digest(): void
    {
        $approved = $this->approve($this->draft());
        $document = $approved->specification;
        $document['instruments'][0] = array_reverse($document['instruments'][0], true);
        $approved->forceFill(['specification' => array_reverse($document, true)])->save();
        app(BenchmarkDesigner::class)->assertFrozen($approved->fresh());
        $this->assertSame($approved->digest, $approved->fresh()->approved_digest);
    }

    #[DataProvider('changedFrozenFields')]
    public function test_a_changed_frozen_input_is_detected_in_the_current_database(string $field, mixed $value): void
    {
        $approved = $this->approve($this->draft());
        [$attribute, $path] = explode(':', $field, 2);
        $data = $approved->$attribute;
        data_set($data, $path, $value);
        $approved->forceFill([$attribute => $data])->save();
        $this->expectException(ValidationException::class);
        app(BenchmarkDesigner::class)->assertFrozen($approved->fresh());
    }

    public static function changedFrozenFields(): array
    {
        return [
            ['specification:brief', 'Different brief'], ['specification:fixtures.0.sha256', str_repeat('f', 64)],
            ['specification:fixtures.0.bytes', 121], ['specification:assertions.0.expected.complete', false],
            ['specification:instruments.0.version', '1.2.0'], ['specification:instruments.0.sha256', str_repeat('f', 64)],
            ['lane_matrix:0.id', 'other-lane'], ['lane_matrix:0.package', 'other/package'],
            ['lane_matrix:0.package_ref.value', '0.23.2'], ['lane_matrix:0.package_ref.sha256', str_repeat('f', 64)],
            ['lane_matrix:0.language', 'python'], ['lane_matrix:0.provider', 'mistral'],
            ['lane_matrix:0.model', 'other-model'], ['lane_matrix:0.harness', 'other-harness'],
            ['lane_matrix:0.harness_ref.value', str_repeat('f', 40)], ['lane_matrix:0.harness_ref.sha256', str_repeat('f', 64)],
            ['lane_matrix:0.mode', 'other-mode'], ['lane_matrix:0.agent', 'other-agent'],
            ['lane_matrix:0.bounds.max_calls', 5], ['lane_matrix:0.bounds.max_steps', 9],
            ['lane_matrix:0.bounds.timeout_seconds', 121], ['lane_matrix:0.bounds.limits.max_output_tokens', 401],
            ['budgets:cost', 2], ['budgets:cost_cap_microusd', 2000000],
        ];
    }

    #[DataProvider('nonOwners')]
    public function test_drafting_does_not_grant_a_guest_or_another_account_approval(string $kind): void
    {
        $draft = $this->draft();
        $owner = User::factory()->create();
        config(['lab_authority.owner_user_id' => $owner->id]);
        $waiting = app(BenchmarkDesigner::class)->requestApproval($draft);
        if ($kind === 'other') {
            $this->actingAs(User::factory()->create(), 'web');
        }
        $this->expectException(HttpException::class);
        try {
            app(BenchmarkDesigner::class)->approve($waiting);
        } finally {
            $this->assertSame('awaiting_approval', $waiting->fresh()->status);
            $this->assertNull($waiting->fresh()->approved_by);
        }
    }

    public static function nonOwners(): array
    {
        return [['guest'], ['other']];
    }

    public function test_revising_reloads_a_tampered_parent_instead_of_borrowing_a_stale_approval(): void
    {
        $approved = $this->approve($this->draft());
        DB::table('benchmark_specs')->where('id', $approved->id)->update(['name' => 'Changed parent']);
        $this->expectException(ValidationException::class);
        try {
            app(BenchmarkDesigner::class)->reviseTest($approved, FrozenTestDefinition::fromArray(self::definitionInput()));
        } finally {
            $this->assertSame(1, BenchmarkSpec::query()->count());
        }
    }

    public function test_even_exact_paid_go_cannot_send_an_rsi_contract_into_the_legacy_scored_runner(): void
    {
        $this->prepareLabAuthority();
        $approved = $this->approve($this->draft());
        DB::table('lab_settings')->updateOrInsert(['key' => 'benchmark.allowed_models'], ['value' => '["anthropic:claude-sonnet-5"]']);
        auth('web')->logout();
        $fake = Prism::fake([]);
        $this->expectException(ValidationException::class);
        try {
            $this->withApprovedLabRun('benchmarks.launch', [], fn () => app(BenchmarkDesigner::class)->launch($approved), $approved->id);
        } finally {
            $this->assertSame(0, BenchmarkRun::query()->count());
            $this->assertSame(0, BenchmarkLane::query()->count());
            $this->assertSame(0, LabPaidWork::query()->count());
            $fake->assertCallCount(0);
        }
    }
}
