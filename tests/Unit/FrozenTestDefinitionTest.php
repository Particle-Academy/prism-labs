<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Benchmarks\CanonicalJson;
use App\Benchmarks\FrozenTestDefinition;
use Illuminate\Validation\ValidationException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FrozenTestFixture;
use Tests\TestCase;

final class FrozenTestDefinitionTest extends TestCase
{
    use FrozenTestFixture;

    public function test_a_bounded_definition_round_trips_without_losing_declared_provenance(): void
    {
        $input = self::definitionInput();
        $definition = FrozenTestDefinition::fromArray($input);
        $stored = json_decode(json_encode($definition->specification(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $roundTrip = FrozenTestDefinition::fromStored($stored, $definition->lanes, $definition->budgets);
        $this->assertSame($definition->specification(), $roundTrip->specification());
        $this->assertSame($input['lanes'], $roundTrip->lanes);
        $this->assertSame(1000000, $roundTrip->budgets['cost_cap_microusd']);
    }

    public function test_native_input_references_cannot_change_a_validated_definition(): void
    {
        $input = self::definitionInput();
        $brief = $input['brief'];
        $originalBrief = $brief;
        $bound = 4;
        $input['brief'] = &$brief;
        $input['lanes'][0]['bounds']['max_calls'] = &$bound;
        $definition = FrozenTestDefinition::fromArray($input);
        $brief = 'Changed after validation';
        $bound = 9;
        $this->assertSame(4, $definition->lanes[0]['bounds']['max_calls']);
        $this->assertSame($originalBrief, $definition->specification()['brief']);
    }

    public function test_object_key_order_is_canonical_but_lane_order_is_part_of_the_document(): void
    {
        $input = self::definitionInput();
        $other = $input['lanes'][0];
        $other['id'] = 'source-candidate';
        $input['lanes'][] = $other;
        $a = FrozenTestDefinition::fromArray($input);
        $input['lanes'][0]['package_ref'] = array_reverse($input['lanes'][0]['package_ref'], true);
        $b = FrozenTestDefinition::fromArray(array_reverse($input, true));
        $this->assertSame(CanonicalJson::encode($a->lanes), CanonicalJson::encode($b->lanes));
        $input['lanes'] = array_reverse($input['lanes']);
        $this->assertNotSame(CanonicalJson::encode($a->lanes), CanonicalJson::encode(FrozenTestDefinition::fromArray($input)->lanes));
    }

    public function test_declaring_an_unavailable_language_or_media_limit_does_not_claim_it_can_execute(): void
    {
        $input = self::definitionInput();
        $input['lanes'][0]['language'] = 'rust';
        $input['lanes'][0]['bounds']['limits'] = ['max_images' => 2, 'max_tool_calls' => 0];
        $this->assertSame($input['lanes'], FrozenTestDefinition::fromArray($input)->lanes);
    }

    #[DataProvider('invalidDefinitions')]
    public function test_ambiguous_or_unbounded_documents_are_refused_without_echoing_input(array $input): void
    {
        $this->expectException(ValidationException::class);
        try {
            FrozenTestDefinition::fromArray($input);
        } catch (ValidationException $failure) {
            $this->assertStringNotContainsString('synthetic-secret-canary', json_encode($failure->errors(), JSON_THROW_ON_ERROR));
            throw $failure;
        }
    }

    public static function invalidDefinitions(): array
    {
        $base = self::definitionInput();
        $changes = [
            'missing brief' => ['brief', null], 'empty brief' => ['brief', '  '],
            'oversized brief' => ['brief', str_repeat('x', 16001)], 'invalid UTF8' => ['brief', "\xFF"],
            'float cap' => ['cost_cap_microusd', 100.5], 'string cap' => ['cost_cap_microusd', '100'],
            'zero cap' => ['cost_cap_microusd', 0], 'over session cap' => ['cost_cap_microusd', 5000001],
            'approval injection' => ['approved_by', 'synthetic-secret-canary'],
            'no lanes' => ['lanes', []], 'no instruments' => ['instruments', []], 'no assertions' => ['assertions', []],
            'mutable release' => ['lanes.0.package_ref.value', 'latest'],
            'range release' => ['lanes.0.package_ref.value', '^0.23.1'],
            'mutable source' => ['lanes.0.harness_ref.value', 'main'],
            'bad reference kind' => ['lanes.0.package_ref.kind', 'branch'],
            'missing reference digest' => ['lanes.0.package_ref.sha256', null],
            'uppercase digest' => ['lanes.0.package_ref.sha256', str_repeat('A', 64)],
            'confusable id' => ['lanes.0.id', 'php-bаseline'], 'padded language' => ['lanes.0.language', ' php'],
            'control model' => ['lanes.0.model', "synthetic-secret-canary\n"],
            'missing mode' => ['lanes.0.mode', null], 'agent type' => ['lanes.0.agent', false],
            'unbounded calls' => ['lanes.0.bounds.max_calls', null], 'zero steps' => ['lanes.0.bounds.max_steps', 0],
            'float timeout' => ['lanes.0.bounds.timeout_seconds', 1.5],
            'empty limits' => ['lanes.0.bounds.limits', []],
            'negative limit' => ['lanes.0.bounds.limits.max_output_tokens', -1],
            'string limit' => ['lanes.0.bounds.limits.max_tool_calls', '2'],
            'infinite limit' => ['lanes.0.bounds.limits.max_input_tokens', INF],
            'unknown instrument' => ['assertions.0.instrument_id', 'synthetic-secret-canary'],
            'nonfinite expectation' => ['assertions.0.expected', NAN],
            'invalid fixture digest' => ['fixtures.0.sha256', 'synthetic-secret-canary'],
            'negative fixture size' => ['fixtures.0.bytes', -1],
        ];
        $cases = [];
        foreach ($changes as $label => [$key, $value]) {
            $input = $base;
            $target = &$input;
            foreach (explode('.', $key) as $part) {
                $target = &$target[$part];
            }
            $target = $value;
            unset($target);
            $cases[$label] = [$input];
        }
        foreach (['lanes', 'fixtures', 'assertions', 'instruments'] as $field) {
            $input = $base;
            $input[$field][] = $input[$field][0];
            $cases['duplicate '.$field] = [$input];
        }
        $input = $base;
        $input['lanes'] = ['named' => $input['lanes'][0]];
        $cases['non-list lanes'] = [$input];
        $input = $base;
        $input['assertions'][0]['expected'] = str_repeat('x', 262144);
        $cases['oversized document'] = [$input];

        return $cases;
    }

    public function test_objects_are_refused_before_their_serializers_can_run(): void
    {
        $input = self::definitionInput();
        $called = false;
        $input['assertions'][0]['expected'] = new class($called) implements JsonSerializable
        {
            public function __construct(private bool &$called) {}

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return 'synthetic-secret-canary';
            }
        };
        $this->expectException(ValidationException::class);
        try {
            FrozenTestDefinition::fromArray($input);
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_recursive_arrays_fail_with_a_bounded_validation_error(): void
    {
        $input = self::definitionInput();
        $input['assertions'][0]['expected'] = &$input;
        $this->expectException(ValidationException::class);
        FrozenTestDefinition::fromArray($input);
    }

    public function test_a_stored_cost_object_cannot_execute_while_validating_the_frozen_document(): void
    {
        $definition = FrozenTestDefinition::fromArray(self::definitionInput());
        $called = false;
        $budgets = $definition->budgets;
        $budgets['cost'] = new class($called) implements JsonSerializable
        {
            public function __construct(private bool &$called) {}

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return 1;
            }
        };
        $this->expectException(ValidationException::class);
        try {
            FrozenTestDefinition::fromStored($definition->specification(), $definition->lanes, $budgets);
        } finally {
            $this->assertFalse($called);
        }
    }
}
