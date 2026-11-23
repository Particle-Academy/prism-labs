<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lab\PaidRunNotAuthorised;
use App\Lab\ProviderFollowupConversation;
use App\Lab\ProviderFollowupPlan;
use App\Lab\ProviderFollowupTool;
use App\Lab\RunAuthority;
use App\Lab\RunScopeFactory;
use App\Models\LabAuthoritySession;
use App\Models\LabRunIntent;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\Concerns\DocsLabLive;
use Tests\TestCase;

/**
 * The provider request-shape check: a frozen plan, an owner GO recorded from
 * one Genie question, every request reserved on the ledger before it is sent,
 * and the run settled at actual cost. Fakes only; the network is blocked and
 * the database is in-memory SQLite.
 */
final class ProviderFollowupCheckTest extends TestCase
{
    use DocsLabLive;

    private const QUESTION = 'q_11111111-2222-4333-8444-555555555555';

    private const OTHER_QUESTION = 'q_99999999-2222-4333-8444-555555555555';

    private const ACTION = 'cli.provider.followup-check';

    /** @var list<string> */
    private array $sent = [];

    private int $anthropicStatus = 200;

    private bool $anthropicCallsTool = false;

    private bool $anthropicStreamTruncated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocsLabLive();
        $owner = User::factory()->create();
        config([
            'lab_authority.owner_user_id' => $owner->id,
            'prism.providers.openai.api_key' => 'synthetic-approval-fixture-only',
            'prism.providers.openai.url' => 'https://api.openai.com/v1',
            'prism.providers.anthropic.url' => 'https://api.anthropic.com/v1',
        ]);
        ProviderFollowupTool::reset();
        // Whether a core sends the fixed conversation depends on its version.
        // A readable one exercises the same transport and ledger on any core;
        // the fixed one is pinned below.
        $this->app->instance(ProviderFollowupConversation::class, new class extends ProviderFollowupConversation
        {
            protected function arguments(): string
            {
                return '{"query":"x"}';
            }
        });
        Http::fake([
            'api.anthropic.com/*' => fn (Request $request) => $this->anthropic($request),
            'api.openai.com/*' => fn (Request $request) => $this->openai($request),
        ]);
    }

    private function approve(string $cap = '2', string $question = self::QUESTION): int
    {
        return Artisan::call('lab:approve-from-ftq', ['--question' => $question, '--answer' => 'run, $2 cap',
            '--action' => self::ACTION, '--cap-usd' => $cap]);
    }

    private function intent(): LabRunIntent
    {
        return LabRunIntent::query()->where('action', self::ACTION)->sole();
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function check(string $goId, string $question = self::QUESTION): array
    {
        $exit = Artisan::call('lab:provider-followup-check', ['--question' => $question, '--approved-run' => $goId]);
        $report = null;
        foreach (explode("\n", Artisan::output()) as $line) {
            if (str_starts_with($line, 'FOLLOWUP_RESULT ')) {
                $report = json_decode(substr($line, 16), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return [$exit, $report];
    }

    private function allocated(LabRunIntent $intent): int
    {
        return (int) LabAuthoritySession::query()->whereKey($intent->authority_session_id)->value('allocated_microusd');
    }

    private function reservations(): int
    {
        return DB::table('lab_call_reservations')->count();
    }

    public function test_no_go_means_no_request_and_no_reservation(): void
    {
        [$exit] = $this->check('01900000-0000-7000-8000-000000000000');

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, $this->reservations());
    }

    public function test_a_go_for_another_action_is_refused(): void
    {
        $docs = $this->approveLive($this->liveInput('S1', judge: false), '1');

        [$exit] = $this->check($docs->id);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->sent);
        $this->assertSame('ready', $docs->refresh()->status, 'A refused action does not consume the docs GO.');
    }

    public function test_a_go_for_another_question_is_refused(): void
    {
        $this->assertSame(0, $this->approve());

        [$exit] = $this->check($this->intent()->id, self::OTHER_QUESTION);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->sent);
        $this->assertSame('ready', $this->intent()->status);
    }

    public function test_a_go_whose_recorded_question_was_changed_is_refused(): void
    {
        $this->assertSame(0, $this->approve());
        $intent = $this->intent();
        // The audit columns sit outside the digest, so the run reads them itself.
        DB::table('lab_run_intents')->where('id', $intent->id)->update(['owner_question_id' => self::OTHER_QUESTION]);

        [$exit, $report] = $this->check($intent->id);

        $this->assertSame(1, $exit);
        $this->assertNull($report);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, $this->reservations());
    }

    public function test_the_go_cannot_be_issued_without_an_owner_question(): void
    {
        $owner = User::query()->sole();
        $this->actingAs($owner, 'web');
        $scope = app(RunScopeFactory::class)->fromAction(self::ACTION, 'lab:agent', ['question' => self::QUESTION]);

        try {
            app(RunAuthority::class)->draft($scope, '2');
            $this->fail('A page-drafted intent must not exist for this action.');
        } catch (PaidRunNotAuthorised) {
            $this->assertSame(0, LabRunIntent::query()->count());
        } finally {
            Auth::guard('web')->logout();
        }
    }

    public function test_a_used_go_and_a_used_question_are_refused(): void
    {
        $this->assertSame(0, $this->approve());
        $intent = $this->intent();
        [$first] = $this->check($intent->id);
        $this->assertSame(0, $first);
        $sent = count($this->sent);

        [$second] = $this->check($intent->id);

        $this->assertSame(1, $second);
        $this->assertCount($sent, $this->sent);
        $this->assertSame(1, $this->approve(), 'A question buys one GO, ever.');
        $this->assertSame(1, LabRunIntent::query()->where('action', self::ACTION)->count());
    }

    public function test_the_cap_for_this_action_cannot_exceed_two_dollars(): void
    {
        foreach (['2.000001', '3', '5'] as $cap) {
            $this->assertSame(1, $this->approve($cap), "A \${$cap} cap must be refused.");
        }
        $this->assertSame(0, LabRunIntent::query()->count());
    }

    public function test_a_request_that_could_cross_the_cap_is_not_sent(): void
    {
        // Room for one worst-case request; after its small actual charge a
        // second worst case no longer fits.
        $this->assertSame(0, $this->approve('0.00011'));
        $intent = $this->intent();

        [$exit, $report] = $this->check($intent->id);

        $this->assertSame(1, $exit);
        $this->assertCount(1, $this->sent);
        $this->assertSame(1, $this->reservations());
        $this->assertTrue($report['stopped_at_cap']);
        $this->assertSame('settled', $intent->refresh()->status);
        $this->assertLessThanOrEqual(110, $intent->settled_microusd);
    }

    public function test_a_widened_plan_is_refused(): void
    {
        foreach ([['question' => self::QUESTION, 'max_tokens' => 4096], ['question' => self::QUESTION, 'provider' => 'mistral'], ['question' => 'nope']] as $input) {
            try {
                app(RunScopeFactory::class)->fromAction(self::ACTION, 'lab:agent', $input);
                $this->fail('A widened plan must be refused.');
            } catch (ValidationException) {
                $this->assertSame([], $this->sent);
            }
        }

        $this->assertSame(0, $this->approve());
        $intent = $this->intent();
        try {
            Artisan::call('lab:provider-followup-check', ['--question' => self::QUESTION, '--approved-run' => $intent->id, '--model' => 'claude-opus-5-5']);
            $this->fail('The command must not take a model option.');
        } catch (InvalidOptionException) {
            $this->assertSame([], $this->sent);
        }

        $payload = $intent->payload;
        $payload['followup']['max_output_tokens'] = 4096;
        DB::table('lab_run_intents')->where('id', $intent->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        [$exit] = $this->check($intent->id);
        $this->assertSame(1, $exit);
        $this->assertSame([], $this->sent);
    }

    public function test_an_endpoint_changed_after_the_go_is_refused(): void
    {
        $this->assertSame(0, $this->approve());
        config(['prism.providers.openai.url' => 'https://proxy.example.test/v1']);

        [$exit] = $this->check($this->intent()->id);

        $this->assertSame(1, $exit);
        $this->assertSame([], $this->sent);
    }

    public function test_the_run_is_settled_at_actual_cost(): void
    {
        $this->assertSame(0, $this->approve());
        $intent = $this->intent();
        $this->assertSame(2_000_000, $this->allocated($intent));

        [$exit, $report] = $this->check($intent->id);

        $this->assertSame(0, $exit);
        $this->assertCount(4, $this->sent, 'One non-streaming and one streaming request per provider.');
        $this->assertSame(4, $this->reservations());
        $charged = (int) DB::table('lab_call_reservations')->where('intent_id', $intent->id)->sum('charged_microusd');
        $this->assertGreaterThan(0, $charged);
        $this->assertSame('settled', $intent->refresh()->status);
        $this->assertSame($charged, $intent->settled_microusd);
        $this->assertSame($charged, $this->allocated($intent));
        $this->assertSame($charged, $report['actual_microusd']);
        $this->assertSame(0, $report['tool_runs']);
        foreach ($report['results'] as $result) {
            $this->assertTrue($result['accepted']);
            $this->assertSame('2xx', $result['status_class']);
        }
        $this->artisan('lab:settle-runs', ['--apply' => true])->expectsOutputToContain('Nothing to settle.')->assertSuccessful();
    }

    public function test_a_rejection_is_a_result_with_no_retry_and_no_echoed_content(): void
    {
        $this->anthropicStatus = 400;
        $this->assertSame(0, $this->approve());
        $intent = $this->intent();

        [$exit, $report] = $this->check($intent->id);

        $this->assertSame(0, $exit, 'A rejection is the answer to the question, not a failed run.');
        $anthropic = array_values(array_filter($report['results'], fn (array $r): bool => $r['provider'] === 'anthropic'));
        $this->assertCount(2, $anthropic);
        foreach ($anthropic as $result) {
            $this->assertFalse($result['accepted']);
            $this->assertSame('4xx', $result['status_class']);
            $this->assertSame('invalid_request_error', $result['error_type']);
            $this->assertSame(0, $result['cost_microusd']);
        }
        $this->assertCount(2, array_filter($this->sent, fn (string $host): bool => $host === 'api.anthropic.com'), 'No retry.');
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ECHOED-PROVIDER-TEXT', $encoded);
        $this->assertStringNotContainsString('synthetic-approval-fixture-only', $encoded);
    }

    public function test_a_stream_that_never_finished_is_not_counted_as_accepted(): void
    {
        $this->anthropicStreamTruncated = true;
        $this->assertSame(0, $this->approve());
        $intent = $this->intent();

        [, $report] = $this->check($intent->id);

        $stream = array_values(array_filter($report['results'], fn (array $r): bool => $r['provider'] === 'anthropic' && $r['mode'] === 'stream'))[0];
        $this->assertFalse($stream['accepted']);
        $this->assertSame('2xx', $stream['status_class']);
        $this->assertSame('stream_error', $stream['error_type']);
        $this->assertSame((int) $intent->refresh()->payload['followup']['targets']['anthropic']['worst_case_microusd'], $stream['cost_microusd'], 'An unknown cost is charged at its worst case.');
    }

    public function test_a_tool_the_model_calls_never_runs(): void
    {
        $this->anthropicCallsTool = true;
        $this->assertSame(0, $this->approve());

        [$exit, $report] = $this->check($this->intent()->id);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $report['tool_runs']);
        $this->assertSame(0, ProviderFollowupTool::runs());
    }

    public function test_an_unconfigured_provider_is_reported_and_not_attempted(): void
    {
        $this->assertSame(0, $this->approve());
        config(['prism.providers.openai.api_key' => '']);

        [$exit, $report] = $this->check($this->intent()->id);

        $this->assertSame(0, $exit);
        $this->assertContains('openai', $report['not_configured']);
        $this->assertNotContains('api.openai.com', $this->sent);
    }

    public function test_the_real_conversation_carries_unreadable_arguments_and_nothing_unsent_is_billed(): void
    {
        $this->app->forgetInstance(ProviderFollowupConversation::class);
        $messages = app(ProviderFollowupConversation::class)->messages();
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertSame(ProviderFollowupConversation::RAW_ARGUMENTS, $messages[1]->toolCalls[0]->arguments);
        $this->assertNull(json_decode(ProviderFollowupConversation::RAW_ARGUMENTS, true));

        $this->assertSame(0, $this->approve());
        [$exit, $report] = $this->check($this->intent()->id);

        $this->assertSame(0, $exit);
        $this->assertCount(4, $report['results']);
        $unsent = array_filter($report['results'], fn (array $r): bool => $r['error_type'] === 'request_not_built');
        // A core that cannot replay the call sends nothing; one that can sends all four.
        $this->assertCount(4 - count($unsent), $this->sent);
        foreach ($unsent as $result) {
            $this->assertFalse($result['accepted']);
            $this->assertSame('none', $result['status_class']);
            $this->assertSame(0, $result['cost_microusd']);
        }
    }

    public function test_the_docs_action_keeps_its_own_limits(): void
    {
        $this->assertContains('cli.docs.run', RunAuthority::SETTLEABLE);
        $docs = $this->approveLive($this->liveInput('S1', judge: false), '3');

        $this->assertSame(3_000_000, $docs->cap_microusd, 'The $2 ceiling belongs to the new action only.');
        $this->assertSame(16, ProviderFollowupPlan::MAX_OUTPUT_TOKENS);
    }

    private function anthropic(Request $request): mixed
    {
        $this->sent[] = 'api.anthropic.com';
        if ($this->anthropicStatus !== 200) {
            return Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'ECHOED-PROVIDER-TEXT']], $this->anthropicStatus);
        }
        $block = $this->anthropicCallsTool
            ? ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['query' => 'x']]
            : ['type' => 'text', 'text' => 'OK'];
        if (($request->data()['stream'] ?? false) === true) {
            $stream = $this->anthropicStream($block);
            if ($this->anthropicStreamTruncated) {
                $stream = substr($stream, 0, (int) strpos($stream, 'event: content_block_stop'));
            }

            return Http::response($stream, 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [$block], 'stop_reason' => $this->anthropicCallsTool ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5]]);
    }

    /** @param array<string, mixed> $block */
    private function anthropicStream(array $block): string
    {
        $tool = $block['type'] === 'tool_use';
        $events = [
            ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
                'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 50, 'output_tokens' => 1]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0,
                'content_block' => $tool ? ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => new \stdClass] : ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0,
                'delta' => $tool ? ['type' => 'input_json_delta', 'partial_json' => '{"query":"x"}'] : ['type' => 'text_delta', 'text' => 'OK']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $tool ? 'tool_use' : 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 5]]],
            ['message_stop', ['type' => 'message_stop']],
        ];

        return implode('', array_map(fn (array $e): string => 'event: '.$e[0]."\ndata: ".json_encode($e[1])."\n\n", $events));
    }

    private function openai(Request $request): mixed
    {
        $this->sent[] = 'api.openai.com';
        $response = ['id' => 'resp_1', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-6-luna',
            'output' => [['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'OK', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 40, 'output_tokens' => 4, 'total_tokens' => 44]];
        if (($request->data()['stream'] ?? false) === true) {
            $events = [
                ['response.created', ['type' => 'response.created', 'response' => [...$response, 'status' => 'in_progress', 'output' => []]]],
                ['response.output_text.delta', ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'delta' => 'OK']],
                ['response.completed', ['type' => 'response.completed', 'response' => $response]],
            ];

            return Http::response(implode('', array_map(fn (array $e): string => 'event: '.$e[0]."\ndata: ".json_encode($e[1])."\n\n", $events)), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response($response);
    }
}
