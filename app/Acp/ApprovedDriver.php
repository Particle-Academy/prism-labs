<?php

declare(strict_types=1);

namespace App\Acp;

use Prism\Acp\Agent\AgentDriver;
use Prism\Acp\Agent\CancelsTurns;
use Prism\Acp\Agent\OwnsProcessTree;
use Prism\Acp\Agent\ReportsModels;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Process\ProcessTree;

final readonly class ApprovedDriver implements AgentDriver, CancelsTurns, OwnsProcessTree, ReportsModels
{
    public function __construct(private AgentDriver&CancelsTurns&OwnsProcessTree&ReportsModels $inner, private ApprovedPrompt $approval) {}

    public function start(): void
    {
        $this->inner->start();
    }

    public function prompt(string|array $content): void
    {
        $this->approval->claimPrompt($content);
        $this->inner->prompt($content);
    }

    public function modelState(): Deferred
    {
        return $this->inner->modelState();
    }

    public function setModel(string $modelId): Deferred
    {
        $this->approval->assertModel($modelId);

        return $this->inner->setModel($modelId);
    }

    public function cancel(): Deferred
    {
        return $this->inner->cancel();
    }

    public function stop(int $graceMs = 5000): Deferred
    {
        return $this->inner->stop($graceMs);
    }

    public function processTree(): ?ProcessTree
    {
        return $this->inner->processTree();
    }

    public function endInput(): void
    {
        $this->inner->endInput();
    }

    public function kill(): void
    {
        $this->inner->kill();
    }

    public function cliSessionId(): ?string
    {
        return $this->inner->cliSessionId();
    }

    public function withheldCredentials(): array
    {
        return $this->inner->withheldCredentials();
    }
}
