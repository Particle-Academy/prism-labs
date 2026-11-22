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

    'codex' => [
        'binary' => env('LAB_ACP_CODEX_BINARY', 'codex'),
        'binary_args' => array_values(array_filter(explode(' ', (string) env('LAB_ACP_CODEX_ARGS', '')))),
        // Empty uses the model selected by the native account/config; Claude remains the page default.
        'model' => env('LAB_ACP_CODEX_MODEL', ''),
    ],

    // The agent works in this directory and nowhere else the Lab chose: a sandbox, not the repository.
    'workspace' => env('LAB_ACP_WORKSPACE', storage_path('app/acp-workspace')),

    // A turn that runs longer than this is cancelled, then the agent is stopped.
    'turn_seconds' => (int) env('LAB_ACP_TURN_SECONDS', 300),

    'session_seconds' => (int) env('LAB_ACP_SESSION_SECONDS', 60),

    // /lab/accounts: added Claude accounts, each a CLI configuration directory the Lab owns. The Lab stores a label,
    // the directory and which one is active -- never a credential; the CLI keeps its own sign-in in that directory.
    // The page reaches prism-acp's TypeScript `claudeAccounts` API through scripts/claude-accounts.mjs.
    'accounts' => [
        'directory' => env('LAB_CLAUDE_ACCOUNTS_DIRECTORY', storage_path('app/claude-accounts')),
        'node' => env('LAB_NODE_BINARY', 'node'),
        // The built TypeScript package, from the sibling working tree, as the Lab consumes every sibling.
        'module' => env('LAB_PRISM_ACP_MODULE', dirname(base_path()).'/prism-acp-ts/dist/index.js'),
        'login_seconds' => (int) env('LAB_CLAUDE_LOGIN_SECONDS', 600),
        // Where a sign-in in progress keeps its state (the URL, then the outcome) between the worker and the page.
        'login_store' => env('LAB_CLAUDE_LOGIN_STORE', 'file'),
    ],
];
