<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Benchmarks\BenchmarkWork;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkRun;
use App\Research\Researcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesBenchmarks;
use Tests\TestCase;

final class NestedResearchAuthorityTest extends TestCase
{
    use ApprovesBenchmarks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareBenchmarkAuthority();
    }

    public function test_native_builder_work_can_research_the_approved_artifact(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $lane = $run->lanes()->firstOrFail();
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned builder research')]);
        $this->beginBenchmarkJob('default');
        $result = app(PaidWorkRegistry::class)->run($lane->paid_work_id, 'benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane),
            fn () => app(Researcher::class)->ask('Generated research for the approved artifact'));
        $this->assertSame('Owned builder research', $result['answer']);
        $fake->assertCallCount(1);
    }

    public function test_a_benchmark_launch_producer_cannot_borrow_builder_tool_authority(): void
    {
        Queue::fake();
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            $this->withBenchmarkRun(fn (BenchmarkRun $run) => app(Researcher::class)->ask('Unbound research'));
        } finally {
            $fake->assertCallCount(0);
        }
    }

    public function test_score_work_cannot_borrow_builder_research_authority(): void
    {
        Queue::fake();
        $run = $this->dispatchedBenchmark();
        $work = $this->boundBenchmarkScore($run->lanes()->firstOrFail());
        $this->beginBenchmarkJob('scoring');
        $fake = Prism::fake([]);
        $this->expectException(PaidRunNotAuthorised::class);
        try {
            app(PaidWorkRegistry::class)->run($work->id, $work->kind, $work->work_key, $work->payload,
                fn () => app(Researcher::class)->ask('Unapproved judge research'));
        } finally {
            $fake->assertCallCount(0);
        }
    }

    #[DataProvider('nestedContexts')]
    public function test_approved_chat_and_voice_keep_their_bounded_research_tools(string $action, array $input): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('Owned nested research')]);
        $result = $this->withApprovedLabRun($action, $input, fn () => app(Researcher::class)->ask('Generated follow-up'));
        $this->assertSame('Owned nested research', $result['answer']);
        $fake->assertCallCount(1);
    }

    public static function nestedContexts(): array
    {
        return [['chat.run', ['provider' => 'anthropic', 'model' => 'owned-model', 'feature' => 'research', 'prompt' => 'Reviewed question']],
            ['agent.voice', ['audio' => base64_encode('Owned synthetic audio'), 'mime' => 'audio/wav']]];
    }
}
