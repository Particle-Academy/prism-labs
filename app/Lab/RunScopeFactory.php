<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\BenchmarkDesigner;
use App\Benchmarks\CanonicalJson;
use App\Benchmarks\CompactionAuthority;
use App\Benchmarks\FrozenTestDefinition;
use App\Benchmarks\RsiLaneProfile;
use App\Learnings\Learning;
use App\Learnings\LearningBacklog;
use App\Models\AcpTurn;
use App\Models\BenchmarkSpec;
use App\Team\Nudge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RunScopeFactory
{
    public const ROUTES = [
        'lab.provider-probes.research' => 'provider.research', 'lab.provider-probes.cache' => 'provider.cache',
        'lab.consensus.store' => 'consensus.collect', 'lab.team.ask' => 'team.ask', 'lab.tasks.agent' => 'tasks.agent',
        'lab.benchmarks.policy-turn' => 'benchmarks.policy-turn', 'lab.chat.run' => 'chat.run',
        'lab.agent.send' => 'agent.send', 'lab.agent.voice' => 'agent.voice', 'lab.acp.send' => 'acp.send',
        'lab.tests.run' => 'tests.run', 'lab.benchmarks.launch' => 'benchmarks.launch',
        'lab.benchmarks.compaction-probe' => 'benchmarks.compaction-probe',
        'lab.benchmarks.compaction-recall' => 'benchmarks.compaction-recall', 'lab.benchmarks.summary-loss' => 'benchmarks.summary-loss',
        'lab.team.nudge' => 'team.nudge', 'lab.evidence.send' => 'evidence.send',
    ];

    public function fromRequest(Request $request): RunScope
    {
        $action = self::ROUTES[$request->route()?->getName() ?? ''] ?? null;
        if ($action === null || $request->allFiles() !== []) {
            throw new PaidRunNotAuthorised;
        }
        $resource = 'lab:agent';
        if ($action === 'benchmarks.launch') {
            $spec = $request->route('spec');
            $resource = $spec instanceof Model ? (string) $spec->getRouteKey() : (string) $spec;
        }

        return $this->fromAction($action, $resource, $request->except(['_token', 'approved_run_id']));
    }

    /** @param array<string, mixed> $input */
    public function fromAction(string $action, string $resource, array $input): RunScope
    {
        if (! in_array($action, RunScope::ACTIONS, true)
            || ($action !== 'benchmarks.launch' && $resource !== 'lab:agent')
            || array_key_exists('_token', $input) || array_key_exists('approved_run_id', $input)) {
            throw new PaidRunNotAuthorised;
        }
        $payload = ['input' => $input, 'runtime' => $this->runtime()];
        if (in_array($action, ['chat.run', 'agent.send', 'agent.voice'], true)) {
            $payload['session'] = app(LabSession::class)->approvalSnapshot();
            $payload['session']['effective'] = LabConversation::settings($action, $input);
        }
        if ($action === 'team.nudge') {
            $ref = $input['ref'] ?? null;
            $learning = is_string($ref) ? Learning::query()->where('ref', $ref)->first() : null;
            if (! $learning instanceof Learning) {
                throw ValidationException::withMessages(['ref' => 'Choose an existing learning before approval.']);
            }
            $payload['nudge'] = app(Nudge::class)->approvalSnapshot([$learning]);
        }
        if ($action === 'evidence.send') {
            $payload['nudge'] = app(Nudge::class)->approvalSnapshot(app(LearningBacklog::class)->open(), true);
        }
        if (($compaction = CompactionAuthority::snapshot($action, $input)) !== null) {
            $payload['compaction'] = $compaction;
        }
        if ($action === 'acp.send') {
            $provider = $input['provider'] ?? 'claude';
            if (! in_array($provider, ['claude', 'codex'], true)
                || ! is_string($input['prompt'] ?? null) || $input['prompt'] === '' || strlen($input['prompt']) > 80000) {
                throw ValidationException::withMessages(['payload' => 'Choose a provider and a bounded text prompt.']);
            }
            $model = $input['model'] ?? config('acp.'.$provider.'.model');
            if (! is_string($model) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\[\]-]{0,199}\z/', $model)) {
                throw ValidationException::withMessages(['model' => 'Choose an explicit model before approving this ACP turn.']);
            }
            $payload['acp'] = ['provider' => $provider, 'model' => $model, 'workspace' => (string) config('acp.workspace'),
                'driver' => config('acp.'.$provider), 'turn_seconds' => config('acp.turn_seconds'),
                'session_seconds' => config('acp.session_seconds'), 'resume_session_id' => null];
            if (isset($input['continue'])) {
                $prior = is_string($input['continue']) && Str::isUuid($input['continue']) ? AcpTurn::query()->find($input['continue']) : null;
                if (! $prior instanceof AcpTurn || $prior->isPending() || ($prior->provider ?? 'claude') !== $provider
                    || ! is_string($prior->cli_session_id) || $prior->cli_session_id === '') {
                    throw ValidationException::withMessages(['continue' => 'Choose a completed conversation from the same provider.']);
                }
                $payload['acp']['resume_session_id'] = $prior->cli_session_id;
                $payload['acp']['previous_digest'] = hash('sha256', CanonicalJson::encode([
                    'id' => $prior->id, 'provider' => $prior->provider, 'model' => $prior->model, 'prompt' => $prior->prompt,
                    'cli_session_id' => $prior->cli_session_id, 'updates' => $prior->updates, 'history_updates' => $prior->history_updates,
                ]));
            }
        }
        if ($action === 'benchmarks.launch') {
            if (! Str::isUuid($resource)) {
                throw new PaidRunNotAuthorised;
            }
            $spec = BenchmarkSpec::query()->find($resource);
            if (! $spec instanceof BenchmarkSpec) {
                throw new PaidRunNotAuthorised;
            }
            $payload['benchmark'] = app(BenchmarkDesigner::class)->approvedSnapshot($spec);
            if ($spec->archetype === FrozenTestDefinition::ARCHETYPE) {
                $profile = app(RsiLaneProfile::class);
                $payload['native_runtime'] = ['harness' => $profile->installedReference(), 'core' => $profile->installedReference('particle-academy/prism')];
            }
        }

        return new RunScope($action, $resource, $payload);
    }

    private function runtime(): array
    {
        $configuration = [];
        // Values controlling paid work, never provider keys or endpoint secrets.
        foreach (['team.coordinator', 'team.voice', 'team.research', 'team.tasks', 'team.endpoints', 'team.timeouts',
            'prism-harness.agent', 'prism-harness.agents', 'prism-harness.context', 'prism-harness.tools', 'capabilities', 'prism-workspace',
            'prism.request_timeout', 'prism.telemetry.capture_content', 'prism.telemetry.capture_media',
            'prism-harness.pricing', 'prism-harness.cost_observer', 'prism-harness.stores', 'prism-harness.tasks',
            'acp.claude.model', 'acp.codex.model'] as $key) {
            $configuration[$key] = config($key);
        }
        $sources = ['composer.lock' => hash_file('sha256', base_path('composer.lock'))];
        if (app()->configurationIsCached()) {
            $sources['cached_configuration'] = hash_file('sha256', app()->getCachedConfigPath());
        }
        foreach (['app', 'config', 'routes'] as $directory) {
            foreach (app(Filesystem::class)->allFiles(base_path($directory)) as $file) {
                if ($file->getExtension() === 'php') {
                    $sources[$directory.'/'.$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        foreach (app(Filesystem::class)->allFiles(resource_path('prompts')) as $file) {
            if ($file->getExtension() === 'md') {
                $sources['resources/prompts/'.$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
            }
        }

        return ['configuration_digest' => hash('sha256', CanonicalJson::encode($configuration)),
            'source_digest' => hash('sha256', CanonicalJson::encode($sources))];
    }
}
