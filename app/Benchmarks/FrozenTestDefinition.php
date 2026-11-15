<?php

declare(strict_types=1);

namespace App\Benchmarks;

use Illuminate\Validation\ValidationException;
use JsonException;

/** Declared inputs only. Neither provenance nor bounds are execution evidence. */
final readonly class FrozenTestDefinition
{
    public const ARCHETYPE = 'rsi-test-v1';

    private function __construct(
        private array $input,
        public array $lanes,
        public array $budgets,
    ) {}

    public static function fromArray(array $input): self
    {
        self::jsonValue($input);
        try {
            $encoded = json_encode($input, JSON_THROW_ON_ERROR, 32);
        } catch (JsonException) {
            self::invalid('JSON');
        }
        self::require(strlen($encoded) <= 262144, 'document size');
        self::keys($input, ['brief', 'fixtures', 'assertions', 'instruments', 'lanes', 'cost_cap_microusd']);
        self::text($input['brief'], 16000, 'brief');
        self::integer($input['cost_cap_microusd'], 1, 5000000, 'cost cap');

        self::rows($input['fixtures'], 128, true, 'fixtures');
        foreach ($input['fixtures'] as $fixture) {
            self::keys($fixture, ['id', 'sha256', 'bytes']);
            self::digest($fixture['sha256'], 'fixture digest');
            self::integer($fixture['bytes'], 0, PHP_INT_MAX, 'fixture size');
        }
        self::rows($input['instruments'], 64, false, 'instruments');
        foreach ($input['instruments'] as $instrument) {
            self::keys($instrument, ['id', 'version', 'sha256']);
            self::version($instrument['version']);
            self::digest($instrument['sha256'], 'instrument digest');
        }
        self::rows($input['assertions'], 128, false, 'assertions');
        $instruments = array_column($input['instruments'], 'id');
        foreach ($input['assertions'] as $assertion) {
            self::keys($assertion, ['id', 'instrument_id', 'expected']);
            self::require(in_array($assertion['instrument_id'], $instruments, true), 'assertion instrument');
        }
        self::rows($input['lanes'], 64, false, 'lanes');
        foreach ($input['lanes'] as $lane) {
            self::keys($lane, ['id', 'language', 'package', 'package_ref', 'provider', 'model', 'harness', 'harness_ref', 'mode', 'agent', 'bounds']);
            foreach (['language', 'package', 'provider', 'model', 'harness', 'mode'] as $key) {
                self::text($lane[$key], 200, 'lane '.$key);
                self::require(preg_match('/^[^\s\p{C}]+$/uD', $lane[$key]) === 1, 'lane '.$key);
            }
            if ($lane['agent'] !== null) {
                self::text($lane['agent'], 200, 'lane agent');
                self::require(preg_match('/^[^\s\p{C}]+$/uD', $lane['agent']) === 1, 'lane agent');
            }
            self::reference($lane['package_ref']);
            self::reference($lane['harness_ref']);
            self::keys($lane['bounds'], ['max_calls', 'max_steps', 'timeout_seconds', 'limits']);
            foreach (['max_calls', 'max_steps', 'timeout_seconds'] as $key) {
                self::integer($lane['bounds'][$key], 1, 2147483647, 'lane bound');
            }
            $limits = $lane['bounds']['limits'];
            self::require(is_array($limits) && $limits !== [] && ! array_is_list($limits) && count($limits) <= 32, 'lane limits');
            foreach ($limits as $key => $value) {
                self::require(is_string($key) && preg_match('/^max_[a-z][a-z0-9_]{0,59}$/D', $key) === 1, 'lane limit name');
                self::integer($value, 0, 2147483647, 'lane limit');
            }
        }

        // Detach native PHP references; readonly arrays can still contain them.
        $input = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);

        return new self($input, $input['lanes'], [
            'cost' => $input['cost_cap_microusd'] / 1000000,
            'cost_cap_microusd' => $input['cost_cap_microusd'],
        ]);
    }

    public static function fromStored(array $specification, array $lanes, array $budgets): self
    {
        self::jsonValue($specification);
        self::jsonValue($lanes);
        self::jsonValue($budgets);
        self::keys($specification, ['contract_version', 'brief', 'fixtures', 'assertions', 'instruments', 'previous_revision']);
        self::require($specification['contract_version'] === 1, 'contract version');
        self::parent($specification['previous_revision']);
        self::keys($budgets, ['cost', 'cost_cap_microusd']);
        $definition = self::fromArray([
            'brief' => $specification['brief'], 'fixtures' => $specification['fixtures'],
            'assertions' => $specification['assertions'], 'instruments' => $specification['instruments'],
            'lanes' => $lanes, 'cost_cap_microusd' => $budgets['cost_cap_microusd'],
        ]);
        self::require(CanonicalJson::encode($budgets) === CanonicalJson::encode($definition->budgets), 'cost representation');

        return $definition;
    }

    public function specification(?array $previous = null): array
    {
        self::parent($previous);

        return [
            'contract_version' => 1, 'brief' => $this->input['brief'], 'fixtures' => $this->input['fixtures'],
            'assertions' => $this->input['assertions'], 'instruments' => $this->input['instruments'],
            'previous_revision' => $previous,
        ];
    }

    private static function parent(mixed $parent): void
    {
        if ($parent === null) {
            return;
        }
        self::keys($parent, ['id', 'digest']);
        self::require(is_string($parent['id']) && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $parent['id']) === 1, 'parent id');
        self::digest($parent['digest'], 'parent digest');
    }

    private static function jsonValue(mixed $value, int $depth = 0): void
    {
        self::require($depth < 32, 'JSON depth');
        if (is_array($value)) {
            foreach ($value as $item) {
                self::jsonValue($item, $depth + 1);
            }
        } else {
            self::require($value === null || is_string($value) || is_bool($value) || is_int($value)
                || (is_float($value) && is_finite($value)), 'JSON value');
        }
    }

    private static function reference(mixed $reference): void
    {
        self::keys($reference, ['kind', 'value', 'sha256']);
        self::digest($reference['sha256'], 'reference digest');
        if ($reference['kind'] === 'release') {
            self::version($reference['value']);
        } else {
            self::require($reference['kind'] === 'source' && is_string($reference['value'])
                && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $reference['value']) === 1, 'immutable source reference');
        }
    }

    private static function version(mixed $value): void
    {
        self::require(is_string($value) && preg_match('/^[0-9][0-9A-Za-z.+_-]{0,79}$/D', $value) === 1, 'exact release version');
    }

    private static function rows(mixed $rows, int $max, bool $empty, string $field): void
    {
        self::require(is_array($rows) && array_is_list($rows) && count($rows) <= $max && ($empty || $rows !== []), $field);
        $seen = [];
        foreach ($rows as $row) {
            self::require(is_array($row) && isset($row['id']) && is_string($row['id'])
                && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $row['id']) === 1, $field.' id');
            self::require(! in_array($row['id'], $seen, true), $field.' duplicate id');
            $seen[] = $row['id'];
        }
    }

    private static function keys(mixed $value, array $keys): void
    {
        self::require(is_array($value), 'object');
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        self::require($actual === $keys, 'object fields');
    }

    private static function text(mixed $value, int $max, string $field): void
    {
        self::require(is_string($value) && trim($value) !== '' && strlen($value) <= $max, $field);
    }

    private static function digest(mixed $value, string $field): void
    {
        self::require(is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1, $field);
    }

    private static function integer(mixed $value, int $min, int $max, string $field): void
    {
        self::require(is_int($value) && $value >= $min && $value <= $max, $field);
    }

    private static function require(bool $condition, string $field): void
    {
        if (! $condition) {
            self::invalid($field);
        }
    }

    private static function invalid(string $field): never
    {
        throw ValidationException::withMessages(['test' => 'Invalid frozen test definition: '.$field.'.']);
    }
}
