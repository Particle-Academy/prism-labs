<?php

namespace Tests\Feature;

use App\Models\User;
use Prism\Browser\BrowserManager;
use Prism\Browser\Contracts\BrowserEngine;
use Prism\Browser\Data\BrowserAction;
use Prism\Browser\Data\Observation;
use Prism\Browser\Security\BrowserPolicy;
use Prism\Browser\Stores\InMemoryAttachmentStore as BrowserAttachments;
use Prism\Harness\PrismHarness;
use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\HumanPlusManager;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Stores\InMemoryAttachmentStore as SurfaceAttachments;
use Tests\TestCase;

class AgentCapabilitiesTest extends TestCase
{
    public function test_browser_and_human_plus_bind_independently_to_one_harness_session(): void
    {
        $user = User::factory()->make(['id' => 42]);
        $session = app(PrismHarness::class)->for($user)->session('dogfood');

        $browser = new BrowserManager($this->browserEngine(), new BrowserAttachments, new BrowserPolicy(['example.com']));
        $browserAttachment = $browser->open($session);

        $humanPlus = new HumanPlusManager($this->relay(), new SurfaceAttachments, TrustPolicy::allowing(['sheet_read']), new ResultGuard);
        $surfaceAttachment = $humanPlus->attach(
            $session,
            new SurfaceInvitation('https://relay.example.com', 'lab_001', str_repeat('a', 32), 'sheet:budget', 'Prism Lab'),
            new Participant('agent:prism', 'Prism', '#7c3aed'),
        );

        $this->assertSame($session->key(), $browserAttachment->owner);
        $this->assertSame($session->key(), $surfaceAttachment->owner);
        $this->assertStringStartsWith('browser_', $browserAttachment->id);
        $this->assertStringStartsWith('surface_', $surfaceAttachment->id);
        $this->assertNotSame($browserAttachment->id, $surfaceAttachment->id);
        $this->assertCount(1, $humanPlus->tools($session, $surfaceAttachment->id));
    }

    private function browserEngine(): BrowserEngine
    {
        return new class implements BrowserEngine
        {
            public function open(string $attachmentId, ?string $checkpoint = null): void {}

            public function navigate(string $attachmentId, string $url): Observation
            {
                return $this->observe($attachmentId);
            }

            public function observe(string $attachmentId): Observation
            {
                return new Observation('obs_1', 'https://example.com', 'https://example.com', 'Example', [], []);
            }

            public function act(string $attachmentId, BrowserAction $action): Observation
            {
                return $this->observe($attachmentId);
            }

            public function checkpoint(string $attachmentId): ?string
            {
                return null;
            }

            public function close(string $attachmentId): void {}
        };
    }

    private function relay(): RelayTransport
    {
        return new class implements RelayTransport
        {
            public function exchange(SurfaceAttachment $attachment, array $frame): array
            {
                $result = $frame['method'] === 'initialize'
                    ? ['protocolVersion' => '2025-06-18', 'capabilities' => []]
                    : ['tools' => [['name' => 'sheet_read', 'description' => 'Read shared state', 'inputSchema' => ['type' => 'object']]]];

                return ['jsonrpc' => '2.0', 'id' => $frame['id'], 'result' => $result];
            }

            public function notify(SurfaceAttachment $attachment, array $frame): void {}

            public function detach(SurfaceAttachment $attachment): void {}
        };
    }
}
