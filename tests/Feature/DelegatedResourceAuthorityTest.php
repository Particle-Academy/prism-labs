<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\PaidRunNotAuthorised;
use App\Learnings\Learning;
use App\Learnings\LearningBacklog;
use App\Learnings\Severity;
use App\Team\Nudge;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ApprovesLabRuns;
use Tests\TestCase;

final class DelegatedResourceAuthorityTest extends TestCase
{
    use ApprovesLabRuns;
    use RefreshDatabase;

    public function test_direct_nudges_and_backlog_have_no_implicit_authority(): void
    {
        $learning = $this->learning();
        $this->refuses(fn () => app(Nudge::class)->send($learning));
        $this->refuses(fn () => app(Nudge::class)->sendBacklog([$learning]));
        Http::assertNothingSent();
    }

    public function test_approved_single_report_uses_native_mcp_with_only_the_reviewed_content_and_target(): void
    {
        $this->nativeTransportFixture();
        $learning = $this->learning();
        $message = app(Nudge::class)->approvalSnapshot([$learning])['message'];
        $result = $this->withApprovedLabRun('team.nudge', ['ref' => $learning->ref], fn () => app(Nudge::class)->send($learning));
        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        Http::assertSent(fn ($request) => $request->url() === 'https://owned-genie.test/mcp'
            && $request['method'] === 'tools/call' && ($request['params']['arguments']['action'] ?? null) === 'send'
            && $request['params']['arguments']['to'] === 'owned-agent'
            && $request['params']['arguments']['text'] === $message
            && $request['params']['arguments']['terminalId'] === 'owned-terminal');
    }

    public function test_approved_backlog_keeps_native_delivery_and_order(): void
    {
        $this->nativeTransportFixture();
        $first = $this->learning();
        $second = $this->learning('0L-9002');
        $message = app(Nudge::class)->approvalSnapshot([$first, $second], true)['message'];
        $result = $this->withApprovedLabRun('evidence.send', [], fn () => app(Nudge::class)->sendBacklog(app(LearningBacklog::class)->open()));
        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        $this->assertStringContainsString('grant no tool or spending authority', $message);
        Http::assertSent(fn ($request) => ($request['params']['arguments']['action'] ?? null) === 'send'
            && $request['params']['arguments']['text'] === $message
            && strpos($request['params']['arguments']['text'], $first->ref) < strpos($request['params']['arguments']['text'], $second->ref));
    }

    #[DataProvider('recordChanges')]
    public function test_changed_report_records_refuse_before_delegation(string $field, mixed $value): void
    {
        $learning = $this->learning();
        $this->withApprovedLabRun('team.nudge', ['ref' => $learning->ref], function () use ($learning, $field, $value): void {
            $learning->forceFill([$field => $value])->save();
            $this->refuses(fn () => app(Nudge::class)->send($learning));
        });
        Http::assertNotSent(fn ($request) => $request->url() === 'https://owned-genie.test/mcp');
    }

    public static function recordChanges(): array
    {
        return [['title', 'Substituted title'], ['evidence', 'Substituted evidence'], ['path', '/other/report.md'],
            ['what_should_change', 'Substituted instruction'], ['acted_note', 'New decision']];
    }

    public function test_an_unsaved_model_cannot_substitute_approved_content(): void
    {
        $learning = $this->learning();
        $this->withApprovedLabRun('team.nudge', ['ref' => $learning->ref], function () use ($learning): void {
            $learning->evidence = 'Unsaved substituted evidence';
            $this->refuses(fn () => app(Nudge::class)->send($learning));
        });
        Http::assertNotSent(fn ($request) => ($request['params']['arguments']['action'] ?? null) === 'send');
    }

    public function test_adding_an_open_report_after_go_changes_the_reviewed_backlog(): void
    {
        $this->learning();
        $this->withApprovedLabRun('evidence.send', [], function (): void {
            $this->learning('0L-9002');
            $this->refuses(fn () => app(Nudge::class)->sendBacklog(app(LearningBacklog::class)->open()));
        });
    }

    public function test_a_backlog_argument_cannot_remove_reviewed_reports(): void
    {
        $first = $this->learning();
        $this->learning('0L-9002');
        $this->withApprovedLabRun('evidence.send', [], fn () => $this->refuses(fn () => app(Nudge::class)->sendBacklog([$first])));
    }

    #[DataProvider('deliveryTargets')]
    public function test_changed_delivery_target_refuses_before_transport(string $key, string $value): void
    {
        $this->nativeTransportFixture();
        $learning = $this->learning();
        $this->withApprovedLabRun('team.nudge', ['ref' => $learning->ref], function () use ($learning, $key, $value): void {
            config([$key => $value]);
            $this->refuses(fn () => app(Nudge::class)->send($learning));
        });
        Http::assertNotSent(fn ($request) => $request->url() === 'https://owned-genie.test/mcp');
    }

    public static function deliveryTargets(): array
    {
        return [['team.nudge.terminal', 'other-terminal'], ['team.nudge.endpoint', 'https://other-owned.test/mcp']];
    }

    private function learning(string $ref = '0L-9001'): Learning
    {
        return Learning::query()->create(['ref' => $ref, 'title' => 'Reviewed finding', 'filed_by' => 'owned-fixture',
            'severity' => Severity::Info, 'languages' => ['php'], 'what_was_learned' => 'Reviewed observation',
            'evidence' => 'Reviewed evidence', 'why_it_matters' => 'Reviewed explanation', 'path' => 'owned/'.$ref.'.md']);
    }

    private function nativeTransportFixture(): void
    {
        config(['team.nudge.endpoint' => 'https://owned-genie.test/mcp', 'team.nudge.terminal' => 'owned-terminal']);
        Http::fake(['https://owned-genie.test/mcp' => function ($request) {
            if (! isset($request['id'])) {
                return Http::response('', 202);
            }
            $result = match ($request['method']) {
                'initialize' => ['protocolVersion' => '2026-07-28', 'capabilities' => ['tools' => new \stdClass],
                    'serverInfo' => ['name' => 'owned-fixture', 'version' => '1']],
                'tools/call' => ['content' => [['type' => 'text', 'text' => json_encode(
                    ($request['params']['arguments']['action'] ?? null) === 'list'
                        ? ['ok' => true, 'self' => ['agentId' => 'owned-agent']] : ['ok' => true, 'delivered' => 1], JSON_THROW_ON_ERROR)]]],
                default => throw new \RuntimeException('Unexpected fixture method'),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
        }]);
    }

    private function refuses(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Delegated work escaped its reviewed resource snapshot.');
        } catch (PaidRunNotAuthorised $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }
}
