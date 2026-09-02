<?php

declare(strict_types=1);

use App\Prompts\PromptFile;

return [
    'agent' => [
        'provider' => env('PRISM_COORDINATOR_PROVIDER', 'anthropic'),
        'model' => env('PRISM_COORDINATOR_MODEL', 'claude-opus-5'),
        'lock_ttl' => (int) env('HARNESS_RUN_LOCK_TTL', 600),
        'lock_wait' => 0,
        'authorize_tools' => false,
        'default' => 'chat',
        'modes' => [
            'chat' => [
                'system_prompt' => PromptFile::content('chat'),
                'tools' => ['*'],
                'max_steps' => (int) env('PRISM_COORDINATOR_MAX_STEPS', 8),

                /*
                 * The Lab checks its own thesis with the same mechanism it
                 * recommends. `verify_claim` runs a NARROWED agent: it can
                 * search, research and fact-check, and it deliberately cannot
                 * file a 0L, touch a workspace or reach the language agents.
                 *
                 * That narrowing is the point. A verifier able to file its own
                 * finding is a second author, not a check — and the failure
                 * this Lab exists to catch is agreement mistaken for
                 * correctness. The verdict comes back framed as data, so the
                 * coordinator weighs it rather than adopting it.
                 */
                'subagents' => [
                    'verify_claim' => [
                        'description' => 'Independently verify ONE specific claim and return a verdict with evidence. The verifier does not see this conversation, cannot file learnings, and may answer "unverified".',
                        'mode' => 'verifier',
                        'max_steps' => 5,
                    ],
                ],
            ],

            'verifier' => [
                'system_prompt' => PromptFile::content('verifier'),
                // Read-only research. No file_learning, no workspace_write, no
                // ask_<lang> — a checker that can act is not only a checker.
                'tools' => ['search_web', 'research', 'fact_check'],
                'max_steps' => 6,
            ],
            'research' => [
                'system_prompt' => PromptFile::content('research'),
                'tools' => ['search_web', 'research', 'ask_ts', 'ask_py', 'file_learning'],
                'max_steps' => 10,
            ],
            'benchmark' => [
                'system_prompt' => PromptFile::content('benchmark'),
                'tools' => ['roster', 'search_web', 'research', 'fact_check', 'file_learning', 'workspace_list', 'workspace_read', 'workspace_write', 'workspace_delete', 'remotion_render'],

                /*
                 * The one irreversible tool in the Lab asks a human first.
                 *
                 * Deleting a benchmark workspace is the only action here that
                 * cannot be undone by running something again: a wrong render
                 * costs minutes, a wrong delete costs the artefact the run was
                 * evaluating. Prism denies by default when no approval answer
                 * is found, so an unattended run cannot slip past it.
                 */
                'requires_approval' => ['workspace_delete'],
                'skills' => ['remotion'],
                'max_steps' => 10,
            ],
        ],
    ],
];
