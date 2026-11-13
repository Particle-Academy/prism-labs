<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\OwnerIdentity;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

/** One bounded allowlist for the page and export. No transcript/workspace/error reads. */
final readonly class RsiRunEvidence
{
    public function __construct(private OwnerIdentity $owner) {}

    public function contract(BenchmarkSpec $spec): array
    {
        $this->owner->requireOwner();
        abort_unless($spec->archetype === FrozenTestDefinition::ARCHETYPE, 404);
        app(BenchmarkDesigner::class)->assertFrozen($spec);

        return ['id' => $spec->id, 'name' => $spec->name, 'revision' => (int) $spec->revision,
            'digest' => $spec->digest, 'status' => $spec->status, 'archetype' => $spec->archetype,
            'surface_mode' => $spec->surface_mode, 'specification' => $spec->specification,
            'lanes' => $spec->lane_matrix, 'budgets' => $spec->budgets,
            'approved_by' => $spec->approved_by, 'approved_digest' => $spec->approved_digest,
            'approved_at' => $spec->approved_at?->toIso8601String()];
    }

    /** Exact review text, before a browser's JSON parser can round integers. */
    public function documents(array $contract): array
    {
        return ['complete' => CanonicalJson::encode($contract),
            'fixtures' => CanonicalJson::encode($contract['specification']['fixtures']),
            'assertions' => CanonicalJson::encode($contract['specification']['assertions'])];
    }

    public function present(BenchmarkRun $run, ?string $selected): array
    {
        $owner = $this->owner->requireOwner();
        $run->refresh()->load('spec');
        $contract = $this->contract($run->spec);
        $intent = LabRunIntent::query()->find($run->approved_run_id);
        // Immutable ownership for history, not live authorization for another call.
        abort_unless($intent instanceof LabRunIntent && $intent->approved_by === $owner->id && $intent->go_by === $owner->id
            && $intent->action === 'benchmarks.launch' && $intent->resource === $run->benchmark_spec_id
            && ($intent->payload['benchmark']['digest'] ?? null) === $contract['digest']
            && ($intent->payload['benchmark']['specification'] ?? null) === $contract['specification']
            && ($intent->payload['benchmark']['lanes'] ?? null) === $contract['lanes']
            && ($intent->payload['benchmark']['budgets'] ?? null) === $contract['budgets']
            && $intent->cap_microusd === $contract['budgets']['cost_cap_microusd'], 404);
        foreach ($contract as $field => $value) {
            if ($field !== 'status') {
                abort_unless(array_key_exists($field, $intent->payload['benchmark'])
                    && CanonicalJson::encode($value) === CanonicalJson::encode($intent->payload['benchmark'][$field]), 404);
            }
        }
        $lanes = $run->lanes()->limit(65)->get();
        abort_if($lanes->count() > 64 || $lanes->count() !== count($contract['lanes']), 409, 'The frozen lane census changed.');
        $root = LabPaidWork::query()->find($run->paid_work_id);
        abort_unless($this->workMatches($root, $intent->id, 'benchmark.run', $run->id,
            ['spec_id' => $run->benchmark_spec_id, 'spec_digest' => $contract['digest'],
                'lanes' => $lanes->map(fn (BenchmarkLane $lane): array => BenchmarkWork::matrixLane($lane))->all(),
                'approved_run_id' => $intent->id], null), 404);
        $declared = array_map(CanonicalJson::encode(...), $contract['lanes']);
        $actual = $lanes->map(fn (BenchmarkLane $lane): string => CanonicalJson::encode($lane->frozen_test_lane))->all();
        sort($declared, SORT_STRING);
        sort($actual, SORT_STRING);
        abort_unless($actual === $declared, 404);
        foreach ($lanes as $index => $lane) {
            abort_unless($lane->ordinal === $index + 1 && is_array($lane->frozen_test_lane)
                && $lane->language === $lane->frozen_test_lane['language'] && $lane->harness === $lane->frozen_test_lane['harness']
                && $lane->provider === $lane->frozen_test_lane['provider'] && $lane->model === $lane->frozen_test_lane['model']
                && $this->workMatches(LabPaidWork::query()->find($lane->paid_work_id), $intent->id, 'benchmark.lane', $lane->id,
                    BenchmarkWork::lanePayload($lane), $root->id), 404);
        }
        abort_if($selected !== null && ! $lanes->contains('id', $selected), 404);
        $rows = $lanes->map(fn (BenchmarkLane $lane): array => $this->lane($lane, $intent))->all();

        return ['contract' => $contract, 'review_documents' => $this->documents($contract), 'run' => ['id' => $run->id, 'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(), 'selected_lane' => $selected ?? ($rows[0]['id'] ?? null),
            'judgment' => 'unjudged', 'artifact_content_captured' => false],
            'allocation' => ['intent_id' => $intent->id, 'cap_microusd' => $intent->cap_microusd,
                'revoked' => $intent->revoked_at !== null, 'expired' => $intent->expires_at?->isPast() ?? false,
                'costs' => $this->costs($intent->id)],
            'lanes' => $rows];
    }

    private function lane(BenchmarkLane $lane, LabRunIntent $intent): array
    {
        $proof = is_array($lane->proof) ? $lane->proof : [];
        $bound = ($proof['spec_digest'] ?? null) === ($intent->payload['benchmark']['digest'] ?? null);
        $classification = $proof['classification'] ?? null;
        $classification = in_array($classification, ['observed', 'environment', 'unsupported', 'admission', 'unknown'], true) ? $classification : 'unknown';
        if ($classification === 'observed' && ! $bound) {
            $classification = 'unknown';
        }
        $observed = $bound && is_array($proof['observed_lane'] ?? null) ? $proof['observed_lane'] : [];
        $declared = $lane->frozen_test_lane;
        $identity = [];
        foreach (['language', 'provider', 'model', 'mode', 'agent', 'package_ref', 'harness_ref'] as $key) {
            // Only the frozen, already bounded contract may name a measured identity.
            $identity[$key] = isset($declared[$key]) && ($observed[$key] ?? null) === $declared[$key] ? $declared[$key] : null;
        }
        $metrics = [];
        foreach (['native_send_ms', 'calls', 'prompt_tokens', 'completion_tokens', 'known_charge_microusd'] as $key) {
            $value = $bound ? ($proof['metrics'][$key] ?? null) : null;
            $metrics[$key] = $this->number($value) && ($key === 'native_send_ms' || is_int($value)) ? $value : null;
        }

        return ['id' => $lane->id, 'ordinal' => (int) $lane->ordinal, 'status' => $lane->status,
            'declared' => $declared, 'observed_identity' => $identity, 'classification' => $classification,
            'judgment' => 'unjudged', 'metrics' => $metrics,
            'run_id' => $bound && $this->identifier($proof['run_id'] ?? null) ? $proof['run_id'] : null,
            'thread_id' => $bound && is_string($proof['thread_id'] ?? null) && preg_match('/\A[1-9][0-9]{0,18}\z/', $proof['thread_id']) ? $proof['thread_id'] : null,
            'trace_id' => null,
            'artifact' => ['sha256' => $bound && is_string($proof['output_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $proof['output_sha256']) ? $proof['output_sha256'] : null,
                'bytes' => $bound && is_int($proof['output_bytes'] ?? null) && $this->number($proof['output_bytes']) ? $proof['output_bytes'] : null, 'content_captured' => false],
            'instruments' => $this->results($intent->payload['benchmark']['specification'], $bound ? ($proof['instrument_results'] ?? null) : null),
            'costs' => $this->costs($intent->id, $lane->paid_work_id ?? 'missing-work'),
            'flow' => $this->flow($lane)];
    }

    private function costs(string $intent, ?string $work = null): array
    {
        $query = DB::table('lab_call_reservations')->where('intent_id', $intent);
        if ($work !== null) {
            $query->where('paid_work_id', $work);
        }
        $row = $query->selectRaw('COUNT(*) AS calls, COALESCE(SUM(maximum_microusd),0) AS reserved, COALESCE(SUM(CASE WHEN settled_at IS NULL THEN maximum_microusd ELSE 0 END),0) AS pending, COALESCE(SUM(CASE WHEN settled_at IS NOT NULL THEN charged_microusd ELSE 0 END),0) AS charged, COALESCE(SUM(CASE WHEN settled_at IS NULL OR usage_known = false THEN 1 ELSE 0 END),0) AS uncertain')->first();

        return ['calls' => (int) $row->calls, 'reserved_microusd' => (int) $row->reserved,
            'pending_microusd' => (int) $row->pending, 'accounted_charge_microusd' => (int) $row->charged,
            'known_charge_microusd' => (int) $row->calls > 0 && (int) $row->uncertain === 0 ? (int) $row->charged : null,
            'uncertain_calls' => (int) $row->uncertain];
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})\z/', $value) === 1;
    }

    /** Validate immutable history only; this never authorizes or claims work. */
    private function workMatches(?LabPaidWork $work, string $intent, string $kind, string $key, array $payload, ?string $parent): bool
    {
        return $work !== null && $work->intent_id === $intent && $work->kind === $kind && $work->work_key === $key
            && $work->parent_id === $parent && CanonicalJson::encode($work->payload) === CanonicalJson::encode($payload)
            && is_string($work->payload_digest) && hash_equals($work->payload_digest,
                hash('sha256', CanonicalJson::encode(['intent' => $intent, 'kind' => $kind, 'key' => $key, 'payload' => $payload, 'parent' => $parent])));
    }

    private function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 9007199254740991;
    }

    /** This profile has exactly one native node; no foreign graph or node output is exported. */
    private function flow(BenchmarkLane $lane): ?array
    {
        $flow = WorkflowRun::query()->find($lane->workflow_run_id, ['id', 'status', 'run_key', 'schema']);
        if ($flow === null || ! is_array($flow->schema) || strlen(CanonicalJson::encode($flow->schema)) > 16384) {
            return null;
        }
        try {
            $graph = app(FancyFlowManager::class)->toGraph($flow->schema);
        } catch (\Throwable) {
            // This is a bounded stored-data read, never an execution path.
            // Malformed native shapes are Unknown; parser details stay private.
            return null;
        }
        $node = $graph->nodes[0] ?? null;
        if (count($graph->nodes) !== 1 || $graph->edges !== [] || $node === null
            || $node->id !== 'lane-'.$lane->ordinal || $node->type !== '@prism-lab/benchmark_lane'
            || $node->config !== ['lane_id' => $lane->id, 'paid_work_id' => $lane->paid_work_id]) {
            return null;
        }
        $rows = DB::table('fancy_flow_workflow_run_nodes')->where('run_key', $flow->run_key)->where('node_id', $node->id)
            ->limit(2)->get(['node_id', 'status', 'attempts', 'claimed_at', 'completed_at']);
        if ($rows->count() > 1) {
            return null;
        }
        $states = ['pending', 'queued', 'running', 'completed', 'partial', 'failed', 'skipped', 'cancelled', 'awaiting_approval', 'awaiting_input', 'awaiting_human'];
        $nodes = $rows->map(fn ($row): array => ['node_id' => $node->id,
            'status' => in_array($row->status, $states, true) ? $row->status : 'unknown',
            'attempts' => is_int($row->attempts) && $this->number($row->attempts) ? $row->attempts : null,
            'claimed_at' => $this->timestamp($row->claimed_at), 'completed_at' => $this->timestamp($row->completed_at)])->all();

        return ['id' => $flow->id, 'status' => in_array($flow->status, $states, true) ? $flow->status : 'unknown', 'nodes' => $nodes];
    }

    private function timestamp(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})?\z/', $value) === 1 ? $value : null;
    }

    private function results(array $specification, mixed $results): array
    {
        $results = is_array($results) ? array_slice($results, 0, 128) : [];
        $out = [];
        $pins = array_column($specification['instruments'], null, 'id');
        foreach ($specification['assertions'] as $assertion) {
            $matches = array_filter($results, fn ($row) => is_array($row) && ($row['assertion_id'] ?? null) === $assertion['id']);
            $row = count($matches) === 1 ? reset($matches) : [];
            $pin = $pins[$assertion['instrument_id']];
            $valid = ($row['instrument'] ?? null) === $pin && in_array($row['status'] ?? null, ['pass', 'fail', 'unknown'], true)
                && (($row['status'] ?? null) === 'unknown' || ($row['actual_instrument'] ?? null) === $pin)
                && match ($row['status'] ?? null) {
                    'pass' => ($row['reason'] ?? null) === 'expectation_met',
                    'fail' => ($row['reason'] ?? null) === 'expectation_not_met',
                    'unknown' => in_array($row['reason'] ?? null, ['unsupported_instrument', 'instrument_identity_changed', 'invalid_expectation', 'output_unavailable', 'output_limit', 'unsupported_schema', 'measurement_unavailable', 'output_depth', 'numeric_profile_limit'], true),
                    default => false,
                };
            $value = $valid ? ($row['observed'] ?? null) : null;
            $safe = null;
            if ($pin['id'] === 'text.equals' && is_array($value) && is_string($value['sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $value['sha256']) && is_int($value['bytes'] ?? null) && $this->number($value['bytes'])) {
                $safe = ['sha256' => $value['sha256'], 'bytes' => $value['bytes']];
            } elseif ($pin['id'] === 'json.schema' && is_array($value) && is_bool($value['valid_json'] ?? null)) {
                $safe = ['valid_json' => $value['valid_json']];
            } elseif (in_array($pin['id'], ['latency.maximum-ms', 'usage.maximum-tokens', 'cost.maximum-microusd'], true)
                && $this->number($value)) {
                $safe = $value;
            }
            $status = $valid && ($row['status'] === 'unknown' || $safe !== null) ? $row['status'] : 'unknown';
            $reasons = ['expectation_met', 'expectation_not_met', 'unsupported_instrument', 'instrument_identity_changed', 'invalid_expectation', 'output_unavailable', 'output_limit', 'unsupported_schema', 'measurement_unavailable', 'output_depth', 'numeric_profile_limit'];
            $reason = $valid && in_array($row['reason'] ?? null, $reasons, true) ? $row['reason'] : 'measurement_unavailable';
            $out[] = ['assertion_id' => $assertion['id'], 'instrument' => $pin, 'status' => $status, 'reason' => $reason, 'observed' => $status === 'unknown' ? null : $safe];
        }

        return $out;
    }
}
