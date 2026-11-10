<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\PaidRunNotAuthorised;
use App\Models\User;
use App\Providers\PrismLabServiceProvider;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

final class GenericFlowAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    #[DataProvider('flowKinds')]
    public function test_generic_flow_has_no_implicit_paid_or_operator_authority(string $kind, bool $otherApproval): void
    {
        $this->prepareLabAuthority();
        config(['prism-browser.sidecar.token' => str_repeat('owned-flow-fixture-', 2)]);
        $provider = new PrismLabServiceProvider($this->app);
        $provider->bindLocalServices();
        $provider->boot();
        $fake = Prism::fake([TextResponseFake::make()->withText('Should not run')]);
        $node = new FlowNode('owned-node', $kind, config: ['harness_scope' => 'lab:agent',
            'provider' => 'anthropic', 'model' => 'unapproved-model', 'mode' => 'commentary', 'tools' => [], 'prompt' => 'Unapproved prompt']);
        $execute = app(ExecutorRegistry::class)->resolveFor($node);
        $this->assertNotNull($execute);
        $operation = function () use ($execute, $node): void {
            $count = User::query()->count();
            try {
                $execute(new ExecutionContext($node, [], fn () => null));
                $this->fail('An unadmitted Flow node invoked a paid Harness session.');
            } catch (PaidRunNotAuthorised $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            $this->assertSame($count, User::query()->count(), 'Refusal must precede session/operator creation.');
        };
        if ($otherApproval) {
            $this->withApprovedLabRun('team.ask', ['question' => 'Unapproved prompt'], $operation);
        } else {
            $operation();
        }
        $fake->assertCallCount(0);
    }

    public static function flowKinds(): array
    {
        return [['@prism-lab/harness_agent', false], ['lab_harness_agent', false],
            ['@prism-lab/harness_agent', true], ['lab_harness_agent', true]];
    }
}
