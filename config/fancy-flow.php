<?php

declare(strict_types=1);

return [
    'structural_kinds' => true,
    'kinds' => [],
    'executors' => [],
    'discover' => [],
    'llm' => [
        'driver' => 'prism',
        'provider' => env('PRISM_COORDINATOR_PROVIDER', 'anthropic'),
        'model' => env('PRISM_COORDINATOR_MODEL', 'claude-opus-5'),
    ],
    'timeout_ms' => null,
    'events' => true,
    'agentic' => true,
    'queue' => [
        'driver' => 'per_node',
        'connection' => env('FANCY_FLOW_QUEUE_CONNECTION'),
        'queue' => env('FANCY_FLOW_QUEUE', 'default'),
        'tries' => 1,
        'backoff' => 0,
        'max_concurrent' => env('FANCY_FLOW_MAX_CONCURRENT'),
        'node_tries' => [],
        'drain_limit' => 0,
    ],
    'guards' => [],
    'persistence' => [
        'enabled' => true,
        'table_prefix' => 'fancy_flow_',
        'recorded_input_max_bytes' => 262_144,
    ],
    'store_prefix' => 'prism_lab_flow:',
];
