<?php

declare(strict_types=1);

namespace App\DocsLab;

use App\Benchmarks\CanonicalJson;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Lab\RunScope;
use App\Models\DocsLabResult;
use App\Models\DocsLabRun;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use Illuminate\Support\Facades\File;
use LastWord\Agent;
use Prism\AgentDocs\DocsAgent;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\WorkingCopy;
use Prism\Harness\PrismHarness;
use Prism\Prism\PrismManager;
use Throwable;

/**
 * Runs scenarios through a lane, writes and re-reads the real files, grades
 * them, and records everything: the docs-agent lab's one entry point, used by
 * `lab:docs-run`, the /lab/docs page and the tests alike.
 *
 * Scripted lane: free, deterministic, no network. Each scenario's realistic
 * tool calls are played through Prism's fake (ScriptedModel), which RUNS them
 * against the agent's real tools, so last-word really writes the .docx.
 *
 * Live lane: a real provider model, PAID, only inside an owner-approved
 * `cli.docs.run` intent whose frozen plan still matches (LivePlan), with every
 * call reserved against the owner's cap (LiveCostObserver) and the optional
 * model judge under the same approval and cap.
 *
 * A scenario PASSES only if every structural check passes and, when the judge
 * was asked for, the judge said pass. A judge that gave no verdict leaves the
 * scenario unjudged, which is not a pass.
 */
final class DocsLabRunner
{
    public function __construct(
        private readonly Scenarios $scenarios,
        private readonly Fixtures $fixtures,
        private readonly Judge $judge,
    ) {}

    public function scripted(string $selection): DocsLabRun
    {
        $scenarios = $this->scenarios->select($selection);
        $run = $this->open('scripted', $selection, AgentVersion::current());
        foreach ($scenarios as $scenario) {
            $previous = app()->resolved(PrismManager::class) ? app(PrismManager::class) : null;
            ScriptedModel::install(ScriptedModel::fromScenario($scenario->scripted));
            try {
                $lane = Exercise::run(app(DocsAgent::class), app(PrismHarness::class), DocsAgent::AGENT, $scenario, $this->fixtures, $this->scope($run, $scenario));
            } finally {
                $previous === null ? app()->forgetInstance(PrismManager::class) : app()->instance(PrismManager::class, $previous);
            }
            $this->record($run, $scenario, $lane, null, null);
        }

        return $this->close($run, null);
    }

    /**
     * Run inside an approved `cli.docs.run` context (ApprovedCliRun) only.
     *
     * @param  array<string, mixed>  $input  scenario, model, judge, judge_model: exactly what was approved
     */
    public function live(array $input, LabRunIntent $intent, RunScope $scope): DocsLabRun
    {
        $plan = $intent->payload['docs'] ?? null;
        // Re-derive now. A scenario edited, a price changed or the agent
        // rebuilt since approval is a different run from the one approved.
        if (! is_array($plan) || CanonicalJson::encode(LivePlan::snapshot($input)) !== CanonicalJson::encode($plan)) {
            throw new PaidRunNotAuthorised;
        }
        $scenarios = $this->scenarios->select((string) $plan['selection']);
        $work = app(PaidWorkRegistry::class)->bind('docs.lab', $intent->id, $plan);
        $context = app(PaidRunContext::class);

        return $context->within($intent->id, $scope, function () use ($context, $intent, $plan, $scenarios, $work): DocsLabRun {
            $context->requireWork('docs.lab', $intent->id, $plan);
            $observer = new LiveCostObserver($intent->id, $intent->authority_session_id, $work->id, $work->payload_digest,
                $plan['provider'], array_values(array_unique(array_filter([$plan['model'], $plan['judge_model']]))),
                fn (): LabRunIntent => app(PaidRunContext::class)->authorizedWork('docs.lab', $intent->id, $plan));
            $run = $this->open('live', (string) $plan['selection'], $plan['agent'], $plan, $intent->id);
            $stopped = null;
            try {
                (new LiveSession($plan, $observer, $intent->cap_microusd))->within(function (LiveSession $live) use ($run, $scenarios, $plan, &$stopped): void {
                    foreach ($scenarios as $scenario) {
                        $scope = $this->scope($run, $scenario);
                        $lane = Exercise::run($live->agent(), $live->harness(), $live->agentName, $scenario, $this->fixtures, $scope);
                        $this->record($run, $scenario, $lane, $plan['judge'] ? $live : null, $scope);
                        if ($lane->stopRun) {
                            $stopped = $lane->error;
                            break;
                        }
                    }
                });
            } finally {
                LabPaidWork::query()->whereKey($work->id)->whereNull('finished_at')->update(['finished_at' => now(), 'updated_at' => now()]);
            }

            return $this->close($run, $stopped, LiveCostObserver::spentFor($intent->id));
        }, work: $work);
    }

    /** Where a run's artefacts live; nothing outside it is ever served. */
    public static function directory(DocsLabRun $run, ?string $scenario = null): string
    {
        $base = rtrim((string) config('docs-lab.artefacts'), '/\\').'/runs/'.$run->id;

        return $scenario === null ? $base : $base.'/'.$scenario;
    }

    private function open(string $lane, string $selection, array $agent, ?array $plan = null, ?string $intent = null): DocsLabRun
    {
        return DocsLabRun::query()->create([
            'lane' => $lane, 'selection' => $selection, 'status' => 'running', 'agent' => $agent,
            'provider' => $plan['provider'] ?? null, 'model' => $plan['model'] ?? null, 'judge_model' => $plan['judge_model'] ?? null,
            'intent_id' => $intent, 'started_at' => now(),
        ]);
    }

    private function close(DocsLabRun $run, ?string $stopped, ?int $cost = null): DocsLabRun
    {
        $results = $run->results()->get();
        $passed = $stopped === null && $results->isNotEmpty() && $results->every(fn (DocsLabResult $r): bool => $r->passed);
        $run->forceFill([
            'status' => $stopped !== null ? 'stopped' : ($passed ? 'passed' : 'failed'),
            'passed' => $passed, 'error' => $stopped === null ? null : substr($stopped, 0, 120),
            'cost_microusd' => $cost, 'finished_at' => now(),
        ])->save();

        return $run->refresh();
    }

    private function record(DocsLabRun $run, Scenario $scenario, LaneRun $lane, ?LiveSession $judgeWith, ?string $scope): DocsLabResult
    {
        $dir = self::directory($run, $scenario->id);
        File::ensureDirectoryExists($dir);
        $artefacts = [];
        $initialDraft = $scenario->store['draft'] === null ? null : $this->fixtures->docx($scenario->store['draft']);
        $working = WorkingCopy::fromStore(new InMemoryDocumentStore($lane->saved, $initialDraft))->bytes;
        $source = null;
        if ($working !== null) {
            $artefacts['source.docx'] = $this->write($dir, 'source.docx', $working);
            $source = Agent::read((string) file_get_contents($dir.'/source.docx'));
        }
        $draft = null;
        if ($lane->result?->draft !== null) {
            $artefacts['draft.docx'] = $this->write($dir, 'draft.docx', $lane->result->draft);
            try {
                // Graded from the file on disk, as a person would open it.
                $draft = Agent::read((string) file_get_contents($dir.'/draft.docx'));
            } catch (Throwable $e) {
                report($e);
            }
            if ($draft !== null) {
                $artefacts['draft.json'] = $this->write($dir, 'draft.json', (string) json_encode(Checks::normalised($draft), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
        $checks = app(Checks::class)->evaluate($scenario, $lane, $source, $draft);
        $structural = collect($checks)->every(fn (array $c): bool => $c['passed']);
        $judged = $judgeWith === null || $scope === null ? null : $this->judge->judge($judgeWith, $scenario, $lane, $source, $draft, $scope);
        $passed = $structural && ($judged === null || $judged['verdict'] === 'pass');
        $artefacts['result.json'] = $this->write($dir, 'result.json', (string) json_encode([
            'scenario' => $scenario->identity(), 'outcome' => $lane->result?->outcome->value, 'message' => $lane->result?->message,
            'checks' => $checks, 'judge' => $judged, 'calls' => $lane->calls, 'model_calls' => $lane->modelCalls, 'error' => $lane->error,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DocsLabResult::query()->create([
            'run_id' => $run->id, 'scenario' => $scenario->id, 'scenario_version' => $scenario->version, 'scenario_digest' => $scenario->digest,
            'outcome' => $lane->result?->outcome->value, 'message' => $lane->result?->message,
            'structural_passed' => $structural, 'judge_verdict' => $judged['verdict'] ?? null, 'judge_reason' => $judged['reason'] ?? null,
            'passed' => $passed, 'checks' => $checks, 'calls' => $lane->calls, 'model_calls' => $lane->modelCalls,
            'error' => $lane->error === null ? null : substr($lane->error, 0, 120), 'artefacts' => $artefacts,
        ]);
    }

    /** @return array{sha256: string, bytes: int} */
    private function write(string $dir, string $name, string $bytes): array
    {
        file_put_contents($dir.'/'.$name, $bytes);

        return ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
    }

    private function scope(DocsLabRun $run, Scenario $scenario): string
    {
        return 'docs-lab:'.$run->id.':'.$scenario->id;
    }
}
