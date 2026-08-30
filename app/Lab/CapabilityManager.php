<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Browser\BrowserManager;
use Prism\Browser\Tools\BrowserToolset;
use Prism\Harness\Sessions\Session;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\HumanPlusManager;
use Prism\HumanPlus\Tools\HumanPlusToolset;
use Prism\Prism\Tool;
use Throwable;

final readonly class CapabilityManager
{
    public function __construct(
        private BrowserManager $browser,
        private BrowserToolset $browserTools,
        private HumanPlusManager $humanPlus,
        private HumanPlusToolset $humanPlusTools,
    ) {}

    public function openBrowser(Session $session): array
    {
        $existing = $session->capability('browser');
        if (is_string($existing['id'] ?? null)) {
            return $existing;
        }
        $attachment = $this->browser->open($session->key());
        $state = ['id' => $attachment->id, 'state' => $attachment->state->value, 'generation' => $attachment->generation];
        $session->usingCapability('browser', $state);

        return $state;
    }

    public function attachHumanPlus(Session $session, SurfaceInvitation $invitation): array
    {
        $attachment = $this->humanPlus->attach($session->key(), $invitation, new Participant('prism-lab', 'Prism Lab', '#8b5cf6'));
        $this->humanPlus->tools($session->key(), $attachment->id);
        $state = ['id' => $attachment->id, 'state' => $attachment->state->value, 'surface' => $invitation->surfaceId, 'application' => $invitation->application];
        $session->usingCapability('human_plus', $state);

        return $state;
    }

    /** @return list<Tool> */
    public function tools(Session $session): array
    {
        $tools = [];
        $browser = $session->capability('browser');
        if (is_string($browser['id'] ?? null)) {
            $tools = [...$tools, ...$this->browserTools->forAttachment($session->key(), $browser['id'])];
        }
        $surface = $session->capability('human_plus');
        if (is_string($surface['id'] ?? null)) {
            $tools = [...$tools, ...$this->humanPlusTools->forAttachment(
                $session->key(), $surface['id'], config('capabilities.human_plus.approval_tools', []),
            )];
        }

        return $tools;
    }

    /** @return array{mode:string,browser:?array<string,mixed>,human_plus:?array<string,mixed>} */
    public function status(Session $session): array
    {
        $browser = $this->refreshBrowser($session);
        $human = $this->refreshHumanPlus($session);
        $mode = $browser !== null && $human !== null ? 'both' : ($browser !== null ? 'browser' : ($human !== null ? 'human_plus' : 'none'));

        return ['mode' => $mode, 'browser' => $browser, 'human_plus' => $human];
    }

    public function closeBrowser(Session $session): void
    {
        $state = $session->capability('browser');
        if (is_string($state['id'] ?? null)) {
            $this->browser->close($session->key(), $state['id']);
        }
        $session->forgetCapability('browser');
    }

    public function detachHumanPlus(Session $session): void
    {
        $state = $session->capability('human_plus');
        if (is_string($state['id'] ?? null)) {
            $this->humanPlus->detach($session->key(), $state['id']);
        }
        $session->forgetCapability('human_plus');
    }

    private function refreshBrowser(Session $session): ?array
    {
        $state = $session->capability('browser');
        if (! is_string($state['id'] ?? null)) {
            return null;
        }
        try {
            $attachment = $this->browser->status($session->key(), $state['id']);

            return [...$state, 'state' => $attachment->state->value, 'generation' => $attachment->generation, 'observation_id' => $attachment->currentObservationId];
        } catch (Throwable $failure) {
            return [...$state, 'state' => 'unavailable', 'failure' => $failure::class];
        }
    }

    private function refreshHumanPlus(Session $session): ?array
    {
        $state = $session->capability('human_plus');
        if (! is_string($state['id'] ?? null)) {
            return null;
        }
        try {
            $attachment = $this->humanPlus->status($session->key(), $state['id']);

            return [...$state, 'state' => $attachment->state->value, 'generation' => $attachment->generation];
        } catch (Throwable $failure) {
            return [...$state, 'state' => 'unavailable', 'failure' => $failure::class];
        }
    }
}
