<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Benchmarks\RsiInstruments;
use App\Benchmarks\RsiJsonSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RsiInstrumentsTest extends TestCase
{
    private function measure(string $id, mixed $expected, ?string $text, array $metrics = [], ?array $pin = null): array
    {
        $registry = new RsiInstruments(new RsiJsonSchema);
        $pins = array_column($registry->catalog(), null, 'id');

        return $registry->measure(['instruments' => [$pin ?? $pins[$id]],
            'assertions' => [['id' => 'check', 'instrument_id' => $id, 'expected' => $expected]]], $text, $metrics)[0];
    }

    public static function controls(): array
    {
        return [
            ['text.equals', 'correct', 'correct', [], 'pass'],
            ['text.equals', 'correct', 'incorrect', [], 'fail'],
            ['text.equals', '0', '0', [], 'pass'],
            ['text.equals', '', '', [], 'pass'],
            ['text.equals', 'correct', null, [], 'unknown'],
            ['text.equals', true, 'true', [], 'unknown'],
            ['json.schema', ['type' => 'object', 'required' => ['done'], 'properties' => ['done' => ['type' => 'boolean']], 'additionalProperties' => false], '{"done":true}', [], 'pass'],
            ['json.schema', ['type' => 'object', 'required' => ['done']], '{}', [], 'fail'],
            ['json.schema', ['type' => 'object', 'additionalProperties' => false], '{"extra":true}', [], 'fail'],
            ['json.schema', ['type' => 'object'], '[]', [], 'fail'],
            ['json.schema', ['type' => 'array', 'items' => ['type' => 'integer']], '[1,2]', [], 'pass'],
            ['json.schema', ['type' => 'array', 'items' => ['type' => 'integer']], '[1,"2"]', [], 'fail'],
            ['json.schema', ['type' => 'integer'], '1.0', [], 'pass'],
            ['json.schema', ['type' => 'integer'], '1e2', [], 'pass'],
            ['json.schema', ['type' => 'integer'], '1.25', [], 'fail'],
            ['json.schema', ['type' => 'number'], '1e999', [], 'unknown'],
            ['json.schema', ['type' => 'object'], '{"value":1e999}', [], 'unknown'],
            ['json.schema', ['type' => 'integer'], '1.00000000000000000001', [], 'unknown'],
            ['json.schema', ['type' => 'integer'], '1e-400', [], 'unknown'],
            ['json.schema', ['type' => 'integer'], '9007199254740993', [], 'unknown'],
            ['json.schema', ['type' => 'null'], 'null', [], 'pass'],
            ['json.schema', ['type' => 'boolean'], 'false', [], 'pass'],
            ['json.schema', ['type' => 'object'], '{broken', [], 'fail'],
            ['json.schema', ['type' => 'object', '$ref' => 'http://127.0.0.1/private'], '{}', [], 'unknown'],
            ['json.schema', ['type' => 'string', 'pattern' => '(a+)+$'], 'anything', [], 'unknown'],
            ['json.schema', ['type' => 'object', 'properties' => null], '{}', [], 'unknown'],
            ['json.schema', ['type' => 'object', 'required' => null], '{}', [], 'unknown'],
            ['json.schema', ['type' => 'object', 'additionalProperties' => null], '{}', [], 'unknown'],
            ['json.schema', ['type' => 'array', 'items' => null], '[]', [], 'unknown'],
            ['latency.maximum-ms', 20, null, ['native_send_ms' => 20], 'pass'],
            ['latency.maximum-ms', 20, null, ['native_send_ms' => 20.01], 'fail'],
            ['latency.maximum-ms', 20, null, [], 'unknown'],
            ['usage.maximum-tokens', 5, null, ['prompt_tokens' => 3, 'completion_tokens' => 2], 'pass'],
            ['usage.maximum-tokens', 4, null, ['prompt_tokens' => 3, 'completion_tokens' => 2], 'fail'],
            ['usage.maximum-tokens', 5, null, ['prompt_tokens' => null, 'completion_tokens' => 2], 'unknown'],
            ['usage.maximum-tokens', 5, null, ['prompt_tokens' => -2, 'completion_tokens' => 3], 'unknown'],
            ['usage.maximum-tokens', 5, null, ['prompt_tokens' => '2', 'completion_tokens' => 3], 'unknown'],
            ['usage.maximum-tokens', 5, null, ['prompt_tokens' => 2.0, 'completion_tokens' => 3], 'unknown'],
            ['usage.maximum-tokens', 5, null, ['prompt_tokens' => PHP_INT_MAX, 'completion_tokens' => 1], 'unknown'],
            ['cost.maximum-microusd', 40000, null, ['known_charge_microusd' => 40000], 'pass'],
            ['cost.maximum-microusd', 39999, null, ['known_charge_microusd' => 40000], 'fail'],
            ['cost.maximum-microusd', 0, null, ['known_charge_microusd' => 0], 'pass'],
            ['cost.maximum-microusd', 40000, null, ['known_charge_microusd' => null], 'unknown'],
            ['cost.maximum-microusd', -1, null, ['known_charge_microusd' => 0], 'unknown'],
        ];
    }

    #[DataProvider('controls')]
    public function test_every_instrument_has_an_independent_positive_and_negative_boundary(string $id, mixed $expected, ?string $text, array $metrics, string $status): void
    {
        $this->assertSame($status, $this->measure($id, $expected, $text, $metrics)['status']);
    }

    public function test_an_unknown_id_or_changed_version_or_hash_never_executes_as_a_known_instrument(): void
    {
        foreach ([['id' => 'text.equals', 'version' => '2.0.0', 'sha256' => str_repeat('a', 64)],
            ['id' => 'text.equals', 'version' => '1.0.0', 'sha256' => str_repeat('a', 64)],
            ['id' => 'shell.execute', 'version' => '1.0.0', 'sha256' => str_repeat('a', 64)]] as $pin) {
            $this->assertSame('unknown', $this->measure($pin['id'], 'secret', 'secret', pin: $pin)['status']);
        }
    }

    public function test_output_and_parser_error_content_never_enter_results(): void
    {
        $private = 'PRIVATE_SYNTHETIC_PROVIDER_OUTPUT';
        $result = $this->measure('text.equals', $private, $private);
        $this->assertSame('pass', $result['status']);
        $this->assertSame(hash('sha256', $private), $result['observed']['sha256']);
        $this->assertStringNotContainsString($private, json_encode($result));
        $broken = $this->measure('json.schema', ['type' => 'object'], '{'.$private);
        $this->assertSame('fail', $broken['status']);
        $this->assertStringNotContainsString($private, json_encode($broken));
    }

    public function test_schema_depth_and_output_size_limits_remain_unknown_without_skipping_constraints(): void
    {
        $schema = ['type' => 'string'];
        for ($i = 0; $i < 18; $i++) {
            $schema = ['type' => 'array', 'items' => $schema];
        }
        $this->assertSame('unknown', $this->measure('json.schema', $schema, '[]')['status']);
        $this->assertSame('unknown', $this->measure('json.schema', ['type' => 'string'], str_repeat('a', 262145))['status']);
        $deep = str_repeat('[', 40).'0'.str_repeat(']', 40);
        $this->assertSame('unknown', $this->measure('json.schema', ['type' => 'array'], $deep)['status']);
        $this->assertSame('pass', $this->measure('json.schema', ['type' => 'string'], '"1e999, 9007199254740993"')['status']);
    }
}
