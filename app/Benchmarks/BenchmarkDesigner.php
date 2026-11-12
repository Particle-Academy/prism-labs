<?php

declare(strict_types=1);

namespace App\Benchmarks;

use App\Lab\AdmissionWriter;
use App\Lab\ModelPolicy;
use App\Lab\OwnerIdentity;
use App\Lab\PaidRunContext;
use App\Lab\PaidRunNotAuthorised;
use App\Lab\PaidWorkRegistry;
use App\Models\BenchmarkLane;
use App\Models\BenchmarkRun;
use App\Models\BenchmarkSpec;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BenchmarkDesigner
{
    public function __construct(private readonly OwnerIdentity $owner) {}

    /** Free draft persistence. Provenance is declared, never measured here. */
    public function draftTest(string $name, FrozenTestDefinition $definition, string $surfaceMode = 'standard'): BenchmarkSpec
    {
        return $this->draft($name, FrozenTestDefinition::ARCHETYPE, $surfaceMode,
            $definition->specification(), [], $definition->lanes, $definition->budgets);
    }

    /** Edits append a new unapproved revision; the old approval is not copied. */
    public function reviseTest(BenchmarkSpec $previous, FrozenTestDefinition $definition): BenchmarkSpec
    {
        return DB::transaction(function () use ($previous, $definition): BenchmarkSpec {
            AdmissionWriter::forSpecification($previous->id);
            $locked = BenchmarkSpec::query()->lockForUpdate()->findOrFail($previous->id);
            $this->assertFrozen($locked);
            if ($locked->archetype !== FrozenTestDefinition::ARCHETYPE) {
                throw ValidationException::withMessages(['test' => 'Only an RSI test can be revised as an RSI test.']);
            }

            return $this->draft($locked->name, FrozenTestDefinition::ARCHETYPE, $locked->surface_mode,
                $definition->specification(['id' => $locked->id, 'digest' => $locked->digest]),
                [], $definition->lanes, $definition->budgets);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $specification
     * @param  array<string, mixed>  $rubric
     * @param  list<array{language:string,harness:string,provider:string,model:string}>  $lanes
     * @param  array<string, int|float>  $budgets
     */
    public function draft(string $name, string $archetype, string $surfaceMode, array $specification, array $rubric, array $lanes, array $budgets): BenchmarkSpec
    {
        if ($archetype === FrozenTestDefinition::ARCHETYPE) {
            FrozenTestDefinition::fromStored($specification, $lanes, $budgets);
            if ($rubric !== [] || trim($name) === '' || strlen($name) > 160) {
                throw ValidationException::withMessages(['test' => 'An RSI test needs a name and has no reviewer rubric.']);
            }
        }
        if (! in_array($surfaceMode, ['standard', 'human_plus'], true)) {
            throw new \InvalidArgumentException('Benchmark surface mode must be standard or human_plus.');
        }
        if ($lanes === []) {
            throw new \InvalidArgumentException('A benchmark must declare at least one lane.');
        }
        foreach ($lanes as $lane) {
            foreach (['language', 'harness', 'provider', 'model'] as $field) {
                if (! isset($lane[$field]) || trim($lane[$field]) === '') {
                    throw new \InvalidArgumentException(sprintf('Every benchmark lane requires [%s].', $field));
                }
            }
        }

        $revision = ((int) BenchmarkSpec::query()->where('name', $name)->max('revision')) + 1;
        $document = compact('name', 'archetype', 'surfaceMode', 'specification', 'rubric', 'lanes', 'budgets', 'revision');

        return BenchmarkSpec::query()->create([
            'revision' => $revision, 'digest' => hash('sha256', CanonicalJson::encode($document)),
            'status' => 'draft', 'name' => $name, 'archetype' => $archetype,
            'surface_mode' => $surfaceMode, 'specification' => $specification,
            'rubric' => $rubric, 'lane_matrix' => $lanes, 'budgets' => $budgets,
        ]);
    }

    public function requestApproval(BenchmarkSpec $spec): BenchmarkSpec
    {
        return $this->transition($spec, 'draft', 'awaiting_approval');
    }

    public function approve(BenchmarkSpec $spec): BenchmarkSpec
    {
        $owner = $this->owner->requireOwner();

        return DB::transaction(function () use ($spec, $owner): BenchmarkSpec {
            AdmissionWriter::forSpecification($spec->id);
            $locked = BenchmarkSpec::query()->lockForUpdate()->findOrFail($spec->id);
            $this->assertFrozen($locked);
            if ($locked->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['specification' => 'Only a waiting frozen specification can be approved.']);
            }
            $locked->forceFill([
                'status' => 'approved', 'approved_at' => now(),
                'approved_by' => $owner->getAuthIdentifier(), 'approved_digest' => $locked->digest,
            ])->save();

            return $locked;
        });
    }

    public function assertFrozen(BenchmarkSpec $spec): void
    {
        if ($spec->archetype === FrozenTestDefinition::ARCHETYPE) {
            FrozenTestDefinition::fromStored($spec->specification, $spec->lane_matrix, $spec->budgets);
            if ($spec->rubric !== []) {
                throw ValidationException::withMessages(['test' => 'An RSI test has no reviewer rubric.']);
            }
        }
        $digest = $spec->digest;
        $document = [
            'name' => $spec->name, 'archetype' => $spec->archetype,
            'surfaceMode' => $spec->surface_mode, 'specification' => $spec->specification,
            'rubric' => $spec->rubric, 'lanes' => $spec->lane_matrix,
            'budgets' => $spec->budgets, 'revision' => (int) $spec->revision,
        ];
        if (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)
            || ! hash_equals($digest, hash('sha256', CanonicalJson::encode($document)))) {
            throw ValidationException::withMessages(['specification' => 'The frozen specification changed. Draft a new revision.']);
        }
    }

    /** Current DB state, never a stale caller model or a listing network request. */
    public function approvedSnapshot(BenchmarkSpec $spec): array
    {
        $this->assertFrozen($spec);
        $approver = User::query()->find($spec->approved_by);
        if ($spec->status !== 'approved' || $spec->approved_at === null || ! $approver instanceof User
            || ! $this->owner->matches($approver) || ! is_string($spec->approved_digest)
            || ! hash_equals($spec->digest, $spec->approved_digest)) {
            throw new PaidRunNotAuthorised;
        }

        $selection = app(ModelPolicy::class)->selection();
        sort($selection, SORT_STRING);
        foreach ($spec->lane_matrix as $lane) {
            if (! in_array($lane['provider'].':'.$lane['model'], $selection, true)) {
                throw new PaidRunNotAuthorised;
            }
        }

        return ['id' => $spec->id, 'digest' => $spec->digest, 'approved_digest' => $spec->approved_digest,
            'approved_by' => $spec->approved_by, 'approved_at' => $spec->approved_at->toIso8601String(),
            'name' => $spec->name, 'revision' => $spec->revision, 'archetype' => $spec->archetype,
            'surface_mode' => $spec->surface_mode, 'specification' => $spec->specification,
            'rubric' => $spec->rubric, 'lanes' => $spec->lane_matrix, 'budgets' => $spec->budgets,
            'allowed_models' => $selection];
    }

    public function launch(BenchmarkSpec $spec): BenchmarkRun
    {
        $intent = app(PaidRunContext::class)->authorizedRun(['benchmarks.launch']);
        if ($intent->resource !== $spec->id) {
            throw new PaidRunNotAuthorised;
        }

        try {
            return DB::transaction(function () use ($spec, $intent): BenchmarkRun {
                AdmissionWriter::forIntent($intent->id);
                $locked = BenchmarkSpec::query()->lockForUpdate()->findOrFail($spec->id);
                if ($locked->archetype === FrozenTestDefinition::ARCHETYPE) {
                    $this->assertFrozen($locked);
                    if ($intent->cap_microusd !== $locked->budgets['cost_cap_microusd']) {
                        throw new PaidRunNotAuthorised;
                    }
                }
                $snapshot = $this->approvedSnapshot($locked);
                if (CanonicalJson::encode($snapshot) !== CanonicalJson::encode($intent->payload['benchmark'] ?? null)
                    || BenchmarkRun::query()->where('approved_run_id', $intent->id)->exists()) {
                    throw new PaidRunNotAuthorised;
                }
                $lanes = $locked->lane_matrix;
                shuffle($lanes);
                $run = BenchmarkRun::query()->create([
                    'benchmark_spec_id' => $locked->id, 'status' => 'queued', 'approved_run_id' => $intent->id,
                    'randomized_order' => array_map(fn (array $lane): string => implode(':', [$lane['language'], $lane['provider'], $lane['model']]), $lanes),
                ]);
                $created = [];
                foreach ($lanes as $ordinal => $lane) {
                    $created[] = BenchmarkLane::query()->create([
                        'benchmark_run_id' => $run->id, 'ordinal' => $ordinal + 1,
                        'language' => $lane['language'], 'harness' => $lane['harness'],
                        'provider' => $lane['provider'], 'model' => $lane['model'], 'status' => 'queued',
                        'frozen_test_lane' => $locked->archetype === FrozenTestDefinition::ARCHETYPE ? $lane : null,
                    ]);
                }
                $matrix = array_map(fn (BenchmarkLane $lane): array => BenchmarkWork::matrixLane($lane), $created);
                $work = app(PaidWorkRegistry::class)->bind('benchmark.run', $run->id,
                    ['spec_id' => $locked->id, 'spec_digest' => $locked->digest, 'lanes' => $matrix, 'approved_run_id' => $intent->id]);
                $run->forceFill(['paid_work_id' => $work->id])->save();
                foreach ($created as $lane) {
                    $bound = app(PaidWorkRegistry::class)->bind('benchmark.lane', $lane->id, BenchmarkWork::lanePayload($lane), $work->id);
                    $lane->forceFill(['paid_work_id' => $bound->id])->save();
                }

                return $run;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // The database uniqueness constraint is the second race barrier.
            throw new PaidRunNotAuthorised;
        }
    }

    private function transition(BenchmarkSpec $spec, string $from, string $to): BenchmarkSpec
    {
        return DB::transaction(function () use ($spec, $from, $to): BenchmarkSpec {
            AdmissionWriter::forSpecification($spec->id);
            $locked = BenchmarkSpec::query()->lockForUpdate()->findOrFail($spec->id);
            $this->assertFrozen($locked);
            if ($locked->status !== $from) {
                throw new \LogicException(sprintf('Benchmark must be [%s] before it can become [%s].', $from, $to));
            }
            $locked->forceFill(['status' => $to])->save();

            return $locked;
        }, 3);
    }
}
