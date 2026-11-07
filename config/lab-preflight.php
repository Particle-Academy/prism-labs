<?php

return [
    'workers' => [
        'directory' => storage_path('framework/lab-worker-heartbeats'),
        'fresh_seconds' => 30,
        'queues' => ['default', 'scoring'],
    ],
    'core_actions' => [
        'cache_seconds' => 300,
        'workflows' => [
            '.github/workflows/tests.yml',
            '.github/workflows/phpstan.yml',
            '.github/workflows/formatting.yml',
            '.github/workflows/require-checker.yml',
            '.github/workflows/factcheck.yml',
        ],
    ],
];
