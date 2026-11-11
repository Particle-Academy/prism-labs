<?php

declare(strict_types=1);

namespace Tests\Feature;

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Notify\GenieInboxNotifier;
use ChangeCouncil\Notify\OwnerEvent;
use ChangeCouncil\Notify\OwnerEventNotifier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CouncilNotifierTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        putenv('GENIE_INBOX_URL');
        putenv('GENIE_INBOX_TOKEN');
        parent::tearDown();
    }

    public function test_the_actual_genie_notifier_sends_a_private_pointer_only_after_commit(): void
    {
        $this->syntheticTransport();
        $this->assertInstanceOf(GenieInboxNotifier::class, app(OwnerEventNotifier::class));
        DB::beginTransaction();
        $change = Council::ledger()->propose($this->proposal());
        Http::assertNothingSent();
        DB::commit();
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($change): bool {
            $this->assertSame('http://127.0.0.1:45551/v1/inbox', $request->url());
            $payload = $request->data();
            $this->assertSame('prism@prism', $payload['to']);
            $this->assertSame('council:'.$change->ref.':awaiting_owner', $payload['dedupeKey']);
            $this->assertSame('https://lab.fixture.invalid/council/changes/'.$change->ref, $payload['link']);
            $this->assertStringContainsString('awaiting_owner', $payload['body']);
            $this->assertStringNotContainsString('PRIVATE-PROPOSAL-CANARY', json_encode($payload));
            $this->assertStringNotContainsString('SYNTHETIC-NOTIFIER-TOKEN', json_encode($payload));
            $directory = storage_path('framework/testing');
            if (! is_dir($directory)) {
                mkdir($directory, recursive: true);
            }
            file_put_contents($directory.'/council-notice-example.json', json_encode(['synthetic' => true, 'payload' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return true;
        });
        $this->assertSame(1, AuditEvent::query()->where('type', 'notify.sent')->count());
        $this->assertStringNotContainsString('SYNTHETIC-NOTIFIER-TOKEN', json_encode(DB::table('council_events')->get()));
    }

    public function test_a_rolled_back_proposal_notifies_nobody_and_commits_no_change(): void
    {
        $this->syntheticTransport();
        DB::beginTransaction();
        Council::ledger()->propose($this->proposal());
        DB::rollBack();
        Http::assertNothingSent();
        $this->assertSame(0, Change::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_missing_transport_is_a_recorded_skip_and_not_delivery(): void
    {
        putenv('GENIE_INBOX_URL');
        putenv('GENIE_INBOX_TOKEN');
        Council::ledger()->propose($this->proposal());
        Http::assertNothingSent();
        $this->assertSame('env_missing', AuditEvent::query()->where('type', 'notify.skipped')->sole()->data['status']);
        $this->assertSame(0, AuditEvent::query()->where('type', 'notify.sent')->count());
    }

    public function test_rotated_tokens_and_429_are_observed_without_rolling_back_or_leaking_secrets(): void
    {
        $this->syntheticTransport(Http::sequence()->push([], 404)->push([], 429, ['Retry-After' => '17']));
        $change = Council::ledger()->propose($this->proposal());
        putenv('GENIE_INBOX_TOKEN=SYNTHETIC-ROTATED-TOKEN');
        app(OwnerEventNotifier::class)->notify(new OwnerEvent(OwnerEvent::ESCALATED, $change->ref, $change->id, 'escalated'));
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame('Bearer SYNTHETIC-NOTIFIER-TOKEN', $requests[0][0]->header('Authorization')[0]);
        $this->assertSame('Bearer SYNTHETIC-ROTATED-TOKEN', $requests[1][0]->header('Authorization')[0]);
        $events = AuditEvent::query()->where('type', 'notify.failed')->orderBy('seq')->get();
        $this->assertSame([404, 429], $events->pluck('data.status')->all());
        $this->assertSame(17, $events[1]->data['retry_after']);
        $this->assertSame('awaiting_owner', $change->fresh()->state);
        $audit = json_encode(DB::table('council_events')->get());
        $this->assertStringNotContainsString('SYNTHETIC-NOTIFIER-TOKEN', $audit);
        $this->assertStringNotContainsString('SYNTHETIC-ROTATED-TOKEN', $audit);
        $this->assertStringNotContainsString('127.0.0.1:45551', $audit);
    }

    public function test_a_native_transport_exception_is_visible_without_rollback_replay_or_private_details(): void
    {
        $this->syntheticTransport();
        Http::fake(fn () => throw new ConnectionException('PRIVATE-TRANSPORT-CANARY http://127.0.0.1:45551 SYNTHETIC-NOTIFIER-TOKEN'));
        $change = Council::ledger()->propose($this->proposal());
        $this->assertSame('awaiting_owner', $change->fresh()->state);
        $this->assertDatabaseCount('council_changes', 1);
        $event = AuditEvent::query()->where('type', 'notify.failed')->sole();
        $this->assertSame(0, $event->data['status']);
        $this->assertSame('network', $event->data['error']);
        $this->assertSame(0, AuditEvent::query()->where('type', 'notify.sent')->count());
        $audit = json_encode(DB::table('council_events')->get());
        foreach (['PRIVATE-TRANSPORT-CANARY', 'SYNTHETIC-NOTIFIER-TOKEN', '127.0.0.1:45551'] as $private) {
            $this->assertStringNotContainsString($private, $audit);
        }
    }

    private function syntheticTransport(mixed $response = null): void
    {
        config(['council.lab_url' => 'https://lab.fixture.invalid/council']);
        Http::fake(['*' => $response ?? Http::response([], 202)]);
        putenv('GENIE_INBOX_URL=http://127.0.0.1:45551');
        putenv('GENIE_INBOX_TOKEN=SYNTHETIC-NOTIFIER-TOKEN');
        Council::notifyGenie();
    }

    private function proposal(): array
    {
        return ['title' => 'PRIVATE-PROPOSAL-CANARY', 'summary' => 'PRIVATE-PROPOSAL-CANARY', 'approach' => 'PRIVATE-PROPOSAL-CANARY', 'classes' => ['declared-breaking'], 'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-harness']], 'evidence' => [], 'links' => [], 'source' => 'agent_request', 'initiator' => 'synthetic@fixture', 'pros' => 'PRIVATE-PROPOSAL-CANARY', 'cons' => 'PRIVATE-PROPOSAL-CANARY'];
    }
}
