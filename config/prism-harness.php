<?php

declare(strict_types=1);

use App\Prompts\PromptFile;

return [
    /*
    |--------------------------------------------------------------------------
    | Context window
    |--------------------------------------------------------------------------
    |
    | THE LAB WAS RUNNING UNBOUNDED, which is the one configuration it has no
    | business being in. `keep_recent` defaults to null and a null keep binds
    | `NoCompaction`, so the Overseer's durable thread — one permanent scope,
    | `lab:agent`, shared by the flyout, the full chat and the Studio — replayed
    | its entire history on every turn. The display list is capped at 100
    | messages; what goes to the MODEL never was. The only bound was a human
    | remembering to type `/clear`.
    |
    | That is a dogfooding failure rather than a tuning oversight. This package's
    | context window is the feature the Lab exists to exercise, this Lab probes
    | it in `CompactionRecallProbe` and `SummaryLossProbe`, and the live agent
    | beside those probes was not using it.
    |
    | Found because the flabs team asked how ours is bounded, having just hit the
    | same thing from the other side: they scoped a harness session by SCENARIO
    | rather than by run, replayed every previous run into each new one, reached
    | 3,580 messages on one thread, and watched verdicts drift. Our benchmark
    | lanes already scope per lane (`benchmark:<run>:<lane>`), so that half was
    | right here; the chat was not.
    |
    | KEEP_RECENT RATHER THAN SUMMARISING, deliberately. This package's own
    | config argues summarisation is the strategy most likely to lose something
    | that matters, and the Lab's own SummaryLossProbe found it keeping detail it
    | was asked to drop. A bounded window with recovery is the honest default;
    | naming a model in HARNESS_SUMMARISE_WITH is how someone opts into the
    | other thing after reading why not to.
    |
    | The sink and the recall are bound in PrismLabServiceProvider, because
    | dropping turns with no way back is the configuration this package calls
    | the worst available — cheap window, agent blind to its own work.
    |
    */
    'context' => [
        'keep_recent' => (int) env('HARNESS_KEEP_RECENT', 24),
    ],

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
            /*
             * The judge. NO TOOLS, deliberately and load-bearingly.
             *
             * The Lab already argues this for `verify_claim` above: a verifier
             * able to file its own finding is a second author, not a check. A
             * judge that could read or write the workspace it is scoring is the
             * same defect — it could look past the receipts at the artifact
             * itself, and the entire claim of this surface is that a score
             * rests on evidence the builder chose to submit and can be
             * re-checked by anyone.
             *
             * One step, because it is handed everything and asked for a verdict.
             */
            'scoring' => [
                'system_prompt' => PromptFile::content('scoring'),
                'tools' => [],
                'skills' => [],
                'max_steps' => 1,
            ],

            /*
             * The overseer calling a live run. No tools and a single step on
             * purpose: it is handed the events and asked for a sentence, so
             * anything it could reach for would only be a way to be slower and
             * less accurate than the batch it was already given.
             */
            'commentary' => [
                'system_prompt' => PromptFile::content('commentary'),
                'tools' => [],
                'skills' => [],
                'max_steps' => 1,
            ],

            /*
             * The worker on /lab/tasks, and the ONE tool it is offered is the
             * one it must not be able to use.
             *
             * `complete_task` is registered nowhere in this application — see
             * App\Tasks\TaskListProbe, which asserts that against the wildcard
             * toolset this file's `chat` mode asks for. The task lane registers
             * it for one session at call time, which is how a consumer opts in,
             * and hands it the application's own authorizer: `authorize_tools`
             * above is false, so the tool refuses.
             *
             * Naming it here rather than `['*']` is the point of the mode. A
             * worker offered every Lab tool could research, file a 0L or write
             * to a workspace on its way to a task it cannot close, and none of
             * that is what this lane is asking about.
             */
            'tasks' => [
                'system_prompt' => PromptFile::content('tasks'),
                'tools' => ['complete_task'],
                'skills' => [],
                'max_steps' => 4,
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
