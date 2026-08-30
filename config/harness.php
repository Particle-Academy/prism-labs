<?php

declare(strict_types=1);

use App\Prompts\PromptFile;

return [
    'agent' => [
        'provider' => env('PRISM_COORDINATOR_PROVIDER', 'anthropic'),
        'model' => env('PRISM_COORDINATOR_MODEL', 'claude-sonnet-4-5'),
        'lock_ttl' => (int) env('HARNESS_RUN_LOCK_TTL', 600),
        'lock_wait' => 0,
        'authorize_tools' => false,
        'default' => 'chat',
        'modes' => [
            'chat' => [
                'system_prompt' => PromptFile::content('chat'),
                'tools' => ['*'],
                'max_steps' => (int) env('PRISM_COORDINATOR_MAX_STEPS', 8),
            ],
            'research' => [
                'system_prompt' => PromptFile::content('research'),
                'tools' => ['search_web', 'research', 'ask_ts', 'ask_py', 'file_learning'],
                'max_steps' => 10,
            ],
            'benchmark' => [
                'system_prompt' => PromptFile::content('benchmark'),
                'tools' => ['roster', 'search_web', 'research', 'fact_check', 'file_learning', 'workspace_list', 'workspace_read', 'workspace_write', 'workspace_delete', 'remotion_render'],
                'skills' => ['remotion'],
                'max_steps' => 10,
            ],
        ],
    ],
];
