<?php

declare(strict_types=1);

namespace App\DocsLab;

use Closure;
use Illuminate\Support\Str;
use Prism\AgentDocs\DocsAgent;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Participants\ParticipantAdapters;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;

/**
 * A harness of its own for ONE approved live run.
 *
 * DocsAgent::edit takes no RunContext, so the only way to cap what it spends
 * is the runtime's root budget (`max_cost_usd`) plus a cost observer, which
 * the harness then requires on every model call. Rather than change the
 * application's harness configuration (which every other part of the Lab
 * shares, and which the approval's runtime fingerprint covers), this builds a
 * PRIVATE AgentRuntime and PrismHarness with the cap, the approved prices, the
 * approved models and this run's observer, over the application's own session
 * store, registries and participants. Nothing global changes; when the run
 * ends the private harness is simply dropped.
 *
 * The agent runs in its own harness namespace per run (`docs-live-…`): the
 * registries are process-wide and refuse a second registration, and a live
 * run must never share tools or modes with the scripted lane's `docs` agent.
 */
final class LiveSession
{
    public const JUDGE_MODE = 'judge';

    public readonly string $agentName;

    public readonly string $judgeName;

    private ?PrismHarness $harness = null;

    private ?DocsAgent $agent = null;

    /** @param array<string, mixed> $plan LivePlan::snapshot() */
    public function __construct(public readonly array $plan, private readonly LiveCostObserver $observer, private readonly int $capMicrousd)
    {
        $suffix = strtolower(Str::random(10));
        $this->agentName = 'docs-live-'.$suffix;
        $this->judgeName = 'docs-judge-'.$suffix;
    }

    public function within(Closure $operation): mixed
    {
        try {
            return $operation($this);
        } finally {
            $this->agent = null;
            $this->harness = null;
        }
    }

    public function harness(): PrismHarness
    {
        if ($this->harness !== null) {
            return $this->harness;
        }
        $agents = (array) config('prism-harness.agents', []);
        $agents[$this->agentName] = ['provider' => $this->plan['provider'], 'model' => $this->plan['model']];
        $agents[$this->judgeName] = ['provider' => $this->plan['provider'], 'model' => $this->plan['judge_model'] ?? $this->plan['model']];
        $runtime = new AgentRuntime(
            app(ModeRegistry::class),
            app(ToolRegistry::class),
            app(ToolAuthorizer::class),
            app(SkillRegistry::class),
            [
                ...(array) config('prism-harness.agent', []),
                'max_cost_usd' => $this->capMicrousd / 1_000_000,
                'max_output_tokens_per_call' => $this->plan['max_output_tokens'],
            ],
            null,
            $this->plan['pricing'],
            fn (): LiveCostObserver => $this->observer,
            agents: $agents,
        );

        return $this->harness = new PrismHarness(
            stores: app(SessionStoreManager::class),
            runtime: $runtime,
            config: (array) config('prism-harness', []),
            participants: app(ParticipantAdapters::class),
        );
    }

    public function agent(): DocsAgent
    {
        return $this->agent ??= new DocsAgent($this->harness(), app(ToolRegistry::class), app(ModeRegistry::class), agent: $this->agentName);
    }

    /** The judge's mode: no tools, one step, its words framed as a verdict. */
    public function installJudge(string $systemPrompt): void
    {
        $modes = app(ModeRegistry::class)->forAgent($this->judgeName);
        if (! in_array(self::JUDGE_MODE, $modes->names(), true)) {
            $modes->register(self::JUDGE_MODE, ['system_prompt' => $systemPrompt, 'tools' => [], 'skills' => [], 'max_steps' => 1], 'prism-labs/docs-lab')
                ->setDefault(self::JUDGE_MODE);
        }
    }
}
