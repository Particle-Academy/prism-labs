<?php

declare(strict_types=1);

namespace App\Benchmarks;

use JsonException;

/** Only built-in deterministic measurements; declared input never selects code. */
final readonly class RsiInstruments
{
    private const IDS = ['text.equals', 'json.schema', 'latency.maximum-ms', 'usage.maximum-tokens', 'cost.maximum-microusd'];

    public function __construct(private RsiJsonSchema $schema) {}

    public function catalog(): array
    {
        $sha = hash('sha256', CanonicalJson::encode([
            'registry' => hash_file('sha256', __FILE__),
            'schema' => hash_file('sha256', __DIR__.'/RsiJsonSchema.php'),
            'canonical' => hash_file('sha256', __DIR__.'/CanonicalJson.php'),
        ]));

        return array_map(fn ($id) => ['id' => $id, 'version' => '1.0.0', 'sha256' => $sha], self::IDS);
    }

    /** Raw response stays in memory; only bounded fixed codes, hashes and numbers leave. */
    public function measure(array $specification, ?string $text, array $metrics): array
    {
        $catalog = array_column($this->catalog(), null, 'id');
        $declared = array_column($specification['instruments'], null, 'id');
        $results = [];
        foreach ($specification['assertions'] as $assertion) {
            $pin = $declared[$assertion['instrument_id']];
            $actual = $catalog[$pin['id']] ?? null;
            $base = ['assertion_id' => $assertion['id'], 'instrument' => $pin, 'actual_instrument' => $actual];
            if ($actual === null || $pin['version'] !== $actual['version'] || ! hash_equals($actual['sha256'], $pin['sha256'])) {
                $results[] = [...$base, 'status' => 'unknown', 'reason' => $actual === null ? 'unsupported_instrument' : 'instrument_identity_changed', 'observed' => null];

                continue;
            }
            $results[] = [...$base, ...$this->evaluate($pin['id'], $assertion['expected'], $text, $metrics)];
        }

        return $results;
    }

    private function evaluate(string $id, mixed $expected, ?string $text, array $metrics): array
    {
        if ($id === 'text.equals') {
            if (! is_string($expected)) {
                return $this->unknown('invalid_expectation');
            }
            if ($text === null) {
                return $this->unknown('output_unavailable');
            }

            return $this->result(hash_equals(hash('sha256', $expected), hash('sha256', $text)), ['sha256' => hash('sha256', $text), 'bytes' => strlen($text)]);
        }
        if ($id === 'json.schema') {
            if (! $this->schema->supported($expected)) {
                return $this->unknown('unsupported_schema');
            }
            if ($text === null || strlen($text) > 262144) {
                return $this->unknown($text === null ? 'output_unavailable' : 'output_limit');
            }
            try {
                $value = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $failure) {
                if ($failure->getCode() === JSON_ERROR_DEPTH) {
                    return $this->unknown('output_depth');
                }

                return $this->result(false, ['valid_json' => false]);
            }
            if (! $this->schema->numbersRepresentable($text)) {
                return $this->unknown('numeric_profile_limit');
            }

            return $this->result($this->schema->matches($value, $expected), ['valid_json' => true]);
        }
        if ((! is_int($expected) && ! is_float($expected)) || ! is_finite((float) $expected) || $expected < 0) {
            return $this->unknown('invalid_expectation');
        }
        $value = match ($id) {
            'latency.maximum-ms' => $metrics['native_send_ms'] ?? null,
            'usage.maximum-tokens' => $this->tokens($metrics),
            'cost.maximum-microusd' => $metrics['known_charge_microusd'] ?? null,
        };
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
            return $this->unknown('measurement_unavailable');
        }

        return $this->result($value <= $expected, $value);
    }

    private function unknown(string $reason): array
    {
        return ['status' => 'unknown', 'reason' => $reason, 'observed' => null];
    }

    private function tokens(array $metrics): ?int
    {
        $input = $metrics['prompt_tokens'] ?? null;
        $output = $metrics['completion_tokens'] ?? null;

        return is_int($input) && is_int($output) && $input >= 0 && $output >= 0 && $input <= PHP_INT_MAX - $output
            ? $input + $output : null;
    }

    private function result(bool $pass, mixed $observed): array
    {
        return ['status' => $pass ? 'pass' : 'fail', 'reason' => $pass ? 'expectation_met' : 'expectation_not_met', 'observed' => $observed];
    }
}
