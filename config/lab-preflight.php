<?php

return [
    'workers' => [
        'directory' => storage_path('framework/lab-worker-heartbeats'),
        'fresh_seconds' => 30,
        'queues' => ['default', 'scoring'],
    ],
    'core_actions' => [
        // Optional read-only token, used only against the fixed GitHub host.
        'token' => env('LAB_GITHUB_READ_TOKEN'),
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
