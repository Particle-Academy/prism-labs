<?php

declare(strict_types=1);

namespace App\Lab;

final class SessionPreflight
{
    public function __construct(
        private readonly WorkerHeartbeats $workers,
        private readonly LabDatabaseStatus $database,
        private readonly ProviderRegistry $providers,
        private readonly CoreActionsStatus $coreActions,
    ) {}

    public function report(bool $freshCore = false): array
    {
        return $this->inspect($this->workers->check(), $freshCore);
    }

    private function inspect(array $workerStatus, bool $freshCore): array
    {
        $checks = [
            'workers' => $workerStatus,
            'database' => $this->database->check(),
            'providers' => $this->providerStatus(),
            'core_actions' => $this->coreActions->check($freshCore),
        ];

        return [
            'ready' => ! in_array(false, array_column($checks, 'ready'), true),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
            'notice' => 'Readiness is not approval to launch. Provider key presence does not verify live access.',
        ];
    }

    public function assertReady(): void
    {
        // Run entrypoints must observe the current main, not reuse a prior green.
        if (! $this->report(freshCore: true)['ready']) {
            throw new SessionNotReady;
        }
    }

    public function assertReadyForExecution(): void
    {
        if (! $this->inspect($this->workers->checkForExecution(), true)['ready']) {
            throw new SessionNotReady;
        }
    }

    private function providerStatus(): array
    {
        $selected = config('team.coordinator.provider');
        $rows = [];
        $ready = false;
        foreach ($this->providers->all() as $provider) {
            $key = $provider['key'];
            if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key)) {
                continue;
            }
            $config = config('prism.providers.'.$key);
            $presence = 'unknown';
            if (is_array($config) && $provider['described']) {
                if ($provider['requiresKey']) {
                    $present = is_string($config['api_key'] ?? null) && trim($config['api_key']) !== '';
                    $presence = $present ? 'present' : 'missing';
                } else {
                    $presence = 'not_required';
                }
            }
            $required = $key === $selected;
            if ($required && $presence === 'present' && $provider['modality'] === 'text') {
                $ready = true;
            }
            $rows[] = ['key' => $key, 'label' => $provider['label'], 'presence' => $presence, 'required' => $required];
        }

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'blocked',
            'message' => $ready ? 'Coordinator provider key is present; live access is unverified.'
                : 'Coordinator provider credentials are missing or cannot be verified from configuration.',
            'providers' => $rows,
        ];
    }
}
