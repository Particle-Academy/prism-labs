<?php

declare(strict_types=1);

namespace App\Lab;

use App\Benchmarks\CanonicalJson;
use App\Benchmarks\FrozenTestDefinition;
use App\Models\LabPaidWork;
use App\Models\LabRunIntent;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PaidWorkRegistry
{
    private const ACTIONS = [
        'overseer.turn' => ['agent.send'], 'acp.turn' => ['acp.send'], 'consensus.collect' => ['consensus.collect'],
        'compaction.reservation' => ['benchmarks.compaction-probe', 'cli.compaction-probe'],
        'compaction.recall' => ['benchmarks.compaction-recall', 'cli.compaction-recall'],
        'compaction.summary-loss' => ['benchmarks.summary-loss', 'cli.compaction-summary-loss'],
        'benchmark.run' => ['benchmarks.launch'], 'benchmark.lane' => ['benchmarks.launch'],
        'benchmark.score' => ['benchmarks.launch'], 'benchmark.commentary' => ['benchmarks.launch'],
    ];

    public function __construct(private PaidRunContext $context, private RunAuthority $authority, private RunScopeFactory $scopes) {}

    /** @param array<string, mixed> $payload */
    public function bind(string $kind, string $key, array $payload, ?string $parentId = null): LabPaidWork
    {
        $intent = $this->context->authorizedRun($this->actions($kind));
        if ($parentId !== null) {
            $parent = $this->lookup($parentId);
            $this->require($parent->intent_id === $intent->id);
            $this->assertStoredDigest($parent);
        }
        $this->assertAdmitted($intent, $kind, $key, $payload, $parentId === null ? null : $parent);

        return $this->insert($intent, $kind, $key, $payload, $parentId);
    }

    /** Bind only an already admitted parent's descendant, never caller approval data.
     * @param  array<string, mixed>  $payload
     */
    public function bindDescendant(string $parentId, string $kind, string $key, array $payload): LabPaidWork
    {
        $parent = $this->lookup($parentId);
        $this->assertStoredDigest($parent);
        $this->require(in_array($kind, ['benchmark.lane', 'benchmark.score', 'benchmark.commentary'], true)
            && in_array($parent->kind, ['benchmark.run', 'benchmark.lane'], true));
        $intent = $this->authority->approvedReference($parent->intent_id);
        $scope = $this->currentScope($intent);
        $this->require(in_array($scope->action, $this->actions($kind), true));
        $this->authority->assertActive($intent->id, $scope);
        $this->assertAdmitted($intent, $kind, $key, $payload, $parent);

        return $this->insert($intent, $kind, $key, $payload, $parent->id);
    }

    /** @param array<string, mixed> $payload */
    public function run(?string $id, string $kind, string $key, array $payload, Closure $operation): mixed
    {
        $work = $this->lookup($id);
        $this->require($work->kind === $kind && $work->work_key === $key && $work->claimed_at === null && $work->finished_at === null);
        $this->assertStoredDigest($work);
        $this->require(hash_equals($work->payload_digest, $this->digest($work->intent_id, $kind, $key, $payload, $work->parent_id)));
        if ($work->parent_id !== null) {
            $parent = $this->lookup($work->parent_id);
            $this->require($parent->intent_id === $work->intent_id);
            $this->assertStoredDigest($parent);
        }
        $intent = $this->authority->approvedReference($work->intent_id);
        $scope = $this->currentScope($intent);
        $this->require(in_array($scope->action, $this->actions($kind), true));
        $this->authority->assertActive($intent->id, $scope, forExecution: true);

        $this->assertAdmitted($intent, $kind, $key, $payload, $work->parent_id === null ? null : $parent);

        return $this->context->within($intent->id, $scope, $operation, executingJob: true, work: $work);
    }

    /** Validate an existing reference in its actual producer context; no claim. */
    public function checkProducer(?string $id, string $kind, string $key, array $payload): LabPaidWork
    {
        $intent = $this->context->authorizedRun($this->actions($kind));
        $work = $this->lookup($id);
        $this->require($work->intent_id === $intent->id && $work->kind === $kind && $work->work_key === $key);
        $this->assertStoredDigest($work);
        $this->require(hash_equals($work->payload_digest, $this->digest($intent->id, $kind, $key, $payload, $work->parent_id)));
        $parent = $work->parent_id === null ? null : $this->lookup($work->parent_id);
        if ($parent !== null) {
            $this->require($parent->intent_id === $intent->id);
            $this->assertStoredDigest($parent);
        }
        $this->assertAdmitted($intent, $kind, $key, $payload, $parent);

        return $work;
    }

    /** Claim root dispatch once; native queued writes stay invisible until commit. */
    public function produce(?string $id, string $kind, string $key, array $payload, Closure $operation): mixed
    {
        $this->require($kind === 'benchmark.run');

        return DB::transaction(function () use ($id, $kind, $key, $payload, $operation): mixed {
            AdmissionWriter::forWork($id);
            $work = $this->checkProducer($id, $kind, $key, $payload);
            $this->require($work->claimed_at === null && $work->finished_at === null);
            $intent = $this->context->authorizedRun($this->actions($kind));

            return $this->context->within($intent->id, $this->currentScope($intent), function () use ($kind, $key, $payload, $operation): mixed {
                $this->context->requireWork($kind, $key, $payload);

                return $operation();
            }, work: $work);
        }, 3);
    }

    public static function actionsFor(string $kind): array
    {
        return self::ACTIONS[$kind] ?? throw new PaidRunNotAuthorised;
    }

    /** A normal PHP host validates a claimed parent; it is not a queue worker. */
    public function checkAcpHostReference(?string $id, string $intentId, string $key, array $payload): void
    {
        $work = $this->lookup($id);
        $this->require($work->kind === 'acp.turn' && $work->work_key === $key && $work->intent_id === $intentId
            && $work->parent_id === null && $work->claimed_at !== null && $work->finished_at === null);
        $this->assertStoredDigest($work);
        $this->require(hash_equals($work->payload_digest, $this->digest($intentId, 'acp.turn', $key, $payload, null)));
    }

    private function currentScope(LabRunIntent $intent): RunScope
    {
        $input = $intent->payload['input'] ?? null;
        $this->require(is_array($input));

        return $this->scopes->fromAction($intent->action, $intent->resource, $input);
    }

    private function lookup(?string $id): LabPaidWork
    {
        $this->require(is_string($id) && Str::isUuid($id));
        $work = LabPaidWork::query()->find($id);
        $this->require($work instanceof LabPaidWork);

        return $work;
    }

    private function actions(string $kind): array
    {
        return self::actionsFor($kind);
    }

    private function assertAdmitted(LabRunIntent $intent, string $kind, string $key, array $payload, ?LabPaidWork $parent): void
    {
        if (($intent->payload['benchmark']['archetype'] ?? null) === FrozenTestDefinition::ARCHETYPE
            && in_array($kind, ['benchmark.score', 'benchmark.commentary'], true)) {
            throw new PaidRunNotAuthorised;
        }
        $input = $intent->payload['input'] ?? [];
        if (array_key_exists('approved_run_id', $payload)) {
            $this->require($payload['approved_run_id'] === $intent->id);
        }
        $admitted = match ($kind) {
            'overseer.turn' => $parent === null && is_string($payload['prompt'] ?? null) && $payload['prompt'] !== ''
                && $payload['prompt'] === ($input['message'] ?? null),
            'acp.turn' => $parent === null && is_string($payload['prompt'] ?? null) && $payload['prompt'] !== ''
                && $payload['prompt'] === ($input['prompt'] ?? null)
                && ($payload['provider'] ?? null) === ($intent->payload['acp']['provider'] ?? null)
                && ($payload['model'] ?? null) === ($intent->payload['acp']['model'] ?? null)
                && ($payload['resume_session_id'] ?? null) === ($intent->payload['acp']['resume_session_id'] ?? null),
            'consensus.collect' => $parent === null && is_string($payload['question'] ?? null) && $payload['question'] !== ''
                && $payload['question'] === ($input['question'] ?? null)
                && ($payload['evidence'] ?? null) === ['brief' => $input['evidence'] ?? ''],
            'compaction.reservation' => $parent === null && $key === $intent->id
                && ($payload['reserved'] ?? null) === (bool) ($input['reserved'] ?? true)
                && ($payload['trigger'] ?? null) === (int) ($input['trigger'] ?? 5000)
                && ($payload['keep'] ?? null) === (int) ($input['keep'] ?? 3),
            'compaction.recall', 'compaction.summary-loss' => $parent === null && $key === $intent->id && $payload === [],
            'benchmark.run' => $parent === null && ($payload['spec_id'] ?? null) === $intent->resource
                && ($payload['spec_digest'] ?? null) === ($intent->payload['benchmark']['digest'] ?? null)
                && $this->matchesApprovedMatrix($intent, $payload['lanes'] ?? null),
            'benchmark.lane' => $parent?->kind === 'benchmark.run' && ($payload['run_id'] ?? null) === $parent->work_key
                && ($payload['lane_id'] ?? null) === $key && $this->matchesFrozenLane($parent, $payload),
            'benchmark.score' => $parent?->kind === 'benchmark.lane' && ($payload['lane_id'] ?? null) === $parent->work_key
                && $key === $parent->work_key && $this->isDigest($payload['proof_digest'] ?? null) && $this->isDigest($payload['receipts_digest'] ?? null),
            'benchmark.commentary' => $parent?->kind === 'benchmark.run' && ($payload['run_id'] ?? null) === $parent->work_key
                && is_int($payload['sequence'] ?? null) && $payload['sequence'] > 0
                && $key === $parent->work_key.':'.$payload['sequence'] && $this->followsCommentary($parent, $payload['sequence']),
            default => false,
        };
        $this->require($admitted);
    }

    private function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function followsCommentary(LabPaidWork $parent, int $sequence): bool
    {
        if ($sequence === 1) {
            return true;
        }
        $previous = LabPaidWork::query()->where('kind', 'benchmark.commentary')->where('parent_id', $parent->id)
            ->where('intent_id', $parent->intent_id)->where('work_key', $parent->work_key.':'.($sequence - 1))->whereNotNull('claimed_at')->first();
        if (! $previous instanceof LabPaidWork) {
            return false;
        }
        $this->assertStoredDigest($previous);

        return true;
    }

    private function matchesApprovedMatrix(LabRunIntent $intent, mixed $lanes): bool
    {
        $expected = $intent->payload['benchmark']['lanes'] ?? null;
        if (! is_array($lanes) || ! array_is_list($lanes) || $lanes === [] || ! is_array($expected) || count($lanes) !== count($expected)) {
            return false;
        }
        $actual = [];
        $ids = [];
        $frozen = [];
        $rsi = ($intent->payload['benchmark']['archetype'] ?? null) === FrozenTestDefinition::ARCHETYPE;
        foreach ($lanes as $lane) {
            if (! is_array($lane) || ! is_string($lane['id'] ?? null) || ! Str::isUuid($lane['id']) || in_array($lane['id'], $ids, true)) {
                return false;
            }
            $ids[] = $lane['id'];
            $actual[] = CanonicalJson::encode($rsi ? ($lane['frozen_test_lane'] ?? null) : array_intersect_key($lane, array_flip(['language', 'harness', 'provider', 'model'])));
        }
        foreach ($expected as $lane) {
            if (! is_array($lane)) {
                return false;
            }
            $frozen[] = CanonicalJson::encode($rsi ? $lane : array_intersect_key($lane, array_flip(['language', 'harness', 'provider', 'model'])));
        }
        sort($actual, SORT_STRING);
        sort($frozen, SORT_STRING);

        return $actual === $frozen;
    }

    private function matchesFrozenLane(LabPaidWork $parent, array $payload): bool
    {
        foreach ($parent->payload['lanes'] ?? [] as $lane) {
            if (($lane['id'] ?? null) === ($payload['lane_id'] ?? null)
                && ($lane['language'] ?? null) === ($payload['language'] ?? null)
                && ($lane['harness'] ?? null) === ($payload['harness'] ?? null)
                && ($lane['provider'] ?? null) === ($payload['provider'] ?? null)
                && ($lane['model'] ?? null) === ($payload['model'] ?? null)
                && CanonicalJson::encode($lane['frozen_test_lane'] ?? null) === CanonicalJson::encode($payload['frozen_test_lane'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function insert(LabRunIntent $intent, string $kind, string $key, array $payload, ?string $parentId): LabPaidWork
    {
        $this->require(preg_match('/^[a-zA-Z0-9:._-]{1,128}$/D', $key) === 1);
        $digest = $this->digest($intent->id, $kind, $key, $payload, $parentId);
        $work = LabPaidWork::query()->where('kind', $kind)->where('work_key', $key)->first();
        if (! $work instanceof LabPaidWork) {
            try {
                $work = LabPaidWork::query()->withSavepointIfNeeded(fn () => LabPaidWork::query()->forceCreate([
                    'intent_id' => $intent->id, 'kind' => $kind, 'work_key' => $key,
                    'parent_id' => $parentId, 'payload' => $payload, 'payload_digest' => $digest,
                ]));
            } catch (UniqueConstraintViolationException) {
                $work = LabPaidWork::query()->useWritePdo()->where('kind', $kind)->where('work_key', $key)->first();
            }
        }
        $this->require($work instanceof LabPaidWork && $work->intent_id === $intent->id && hash_equals($work->payload_digest, $digest));
        $this->assertStoredDigest($work);

        return $work;
    }

    private function assertStoredDigest(LabPaidWork $work): void
    {
        $this->require(is_array($work->payload) && is_string($work->payload_digest)
            && hash_equals($work->payload_digest, $this->digest($work->intent_id, $work->kind, $work->work_key, $work->payload, $work->parent_id)));
    }

    private function digest(string $intentId, string $kind, string $key, array $payload, ?string $parentId): string
    {
        return hash('sha256', CanonicalJson::encode(['intent' => $intentId, 'kind' => $kind, 'key' => $key, 'payload' => $payload, 'parent' => $parentId]));
    }

    private function require(bool $allowed): void
    {
        if (! $allowed) {
            throw new PaidRunNotAuthorised;
        }
    }
}
