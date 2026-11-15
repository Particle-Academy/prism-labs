<?php

declare(strict_types=1);

namespace Tests\Concerns;

trait FrozenTestFixture
{
    /** Synthetic declared identities; never claims about installed packages. */
    private static function definitionInput(): array
    {
        return [
            'brief' => 'Exercise a bounded Harness thread and compare its recorded result.',
            'fixtures' => [['id' => 'thread-input', 'sha256' => str_repeat('a', 64), 'bytes' => 120]],
            'assertions' => [['id' => 'answer', 'instrument_id' => 'native-check', 'expected' => ['complete' => true]]],
            'instruments' => [['id' => 'native-check', 'version' => '1.0.0', 'sha256' => str_repeat('b', 64)]],
            'lanes' => [[
                'id' => 'php-baseline', 'language' => 'php', 'package' => 'particle-academy/prism-harness',
                'package_ref' => ['kind' => 'release', 'value' => '0.23.1', 'sha256' => str_repeat('c', 64)],
                'provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'harness' => 'prism-harness',
                'harness_ref' => ['kind' => 'source', 'value' => str_repeat('d', 40), 'sha256' => str_repeat('e', 64)],
                'mode' => 'test', 'agent' => null,
                'bounds' => ['max_calls' => 4, 'max_steps' => 8, 'timeout_seconds' => 120,
                    'limits' => ['max_input_tokens' => 2000, 'max_output_tokens' => 400, 'max_tool_calls' => 2]],
            ]],
            'cost_cap_microusd' => 1000000,
        ];
    }
}
