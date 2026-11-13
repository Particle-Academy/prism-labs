<?php

declare(strict_types=1);

namespace App\Benchmarks;

/** Finite local schema profile. Unsupported keywords never disappear silently. */
final class RsiJsonSchema
{
    private const TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

    private const NUMBER_TOKENS = <<<'REGEX'
~"(?:[^"\\]++|\\.)*+"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~
REGEX;

    /** Called after JSON decoding. Refuse numbers whose rounding can change type. */
    public function numbersRepresentable(string $json): bool
    {
        if (preg_match_all(self::NUMBER_TOKENS, $json, $matches) === false) {
            return false;
        }
        foreach ($matches[0] as $token) {
            $number = (float) $token;
            if (strlen($token) > 128 || ! is_finite($number) || abs($number) > 9007199254740991) {
                return false;
            }
            preg_match('/\A-?([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/', $token, $parts);
            $fraction = $parts[2] ?? '';
            $digits = $parts[1].$fraction;
            if (trim($digits, '0') === '') {
                continue;
            }
            $exponent = (float) ($parts[3] ?? 0);
            if ($number === 0.0 || abs($exponent) > 400) {
                return false;
            }
            $trailing = strlen($digits) - strlen(rtrim($digits, '0'));
            $integer = $exponent >= strlen($fraction) - $trailing;
            if (floor($number) === $number && ! $integer) {
                return false;
            }
        }

        return true;
    }

    public function supported(mixed $schema, int $depth = 0, int &$nodes = 0): bool
    {
        if ($depth > 16 || ++$nodes > 512 || ! is_array($schema) || ! in_array($schema['type'] ?? null, self::TYPES, true)) {
            return false;
        }
        $allowed = match ($schema['type']) {
            'object' => ['type', 'properties', 'required', 'additionalProperties'],
            'array' => ['type', 'items'],
            default => ['type'],
        };
        if (array_diff(array_keys($schema), $allowed) !== []) {
            return false;
        }
        if ($schema['type'] === 'object') {
            $properties = array_key_exists('properties', $schema) ? $schema['properties'] : [];
            $required = array_key_exists('required', $schema) ? $schema['required'] : [];
            if (! is_array($properties) || ($properties !== [] && array_is_list($properties)) || count($properties) > 128
                || ! is_array($required) || ! array_is_list($required) || count($required) > 128
                || ! is_bool(array_key_exists('additionalProperties', $schema) ? $schema['additionalProperties'] : true)) {
                return false;
            }
            foreach ($required as $name) {
                if (! is_string($name)) {
                    return false;
                }
            }
            foreach ($properties as $name => $child) {
                if (! is_string($name) || ! $this->supported($child, $depth + 1, $nodes)) {
                    return false;
                }
            }
        }
        if ($schema['type'] === 'array' && array_key_exists('items', $schema) && ! $this->supported($schema['items'], $depth + 1, $nodes)) {
            return false;
        }

        return true;
    }

    /** Call only after supported(); recursion and schema nodes are bounded there. */
    public function matches(mixed $value, array $schema): bool
    {
        $type = $schema['type'];
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
            'null' => $value === null,
        };
        if (! $valid) {
            return false;
        }
        if ($type === 'object') {
            $fields = get_object_vars($value);
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $name) {
                if (! array_key_exists($name, $fields)) {
                    return false;
                }
            }
            foreach ($fields as $name => $item) {
                if (array_key_exists($name, $properties)) {
                    if (! $this->matches($item, $properties[$name])) {
                        return false;
                    }
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    return false;
                }
            }
        }
        if ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $item) {
                if (! $this->matches($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
