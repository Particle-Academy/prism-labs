<?php

declare(strict_types=1);

/*
 * The ACP agent the Lab runs: particle-academy/prism-acp, served by `php artisan lab:acp-agent` and driven by
 * RunAcpTurnJob as an ACP client would drive it. See App\Acp\AcpClient for why the host runs under the package's own
 * process-tree containment.
 */

return [
    // The Claude CLI the agent drives. Tests point this at prism-acp's fake CLI.
    'claude' => [
        'binary' => env('LAB_ACP_CLAUDE_BINARY', 'claude'),
        // Arguments before the CLI's own: an interpreter's script, when `binary` is an interpreter.
        'binary_args' => array_values(array_filter(explode(' ', (string) env('LAB_ACP_CLAUDE_ARGS', '')))),
        'model' => env('LAB_ACP_MODEL', 'haiku'),
    ],

    // The agent works in this directory and nowhere else the Lab chose: a sandbox, not the repository.
    'workspace' => storage_path('app/acp-workspace'),

    // A turn that runs longer than this is cancelled, then the agent is stopped.
    'turn_seconds' => (int) env('LAB_ACP_TURN_SECONDS', 300),
];
