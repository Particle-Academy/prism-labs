<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Language agent endpoints
    |---------------------------------------------------------------------------
    |
    | Where each language agent's MCP server listens. Loopback by default: these
    | agents run beside the Lab on the same machine, and an agent that can run a
    | test suite and spend tokens is remote code execution wearing a friendly
    | name. It has no business being reachable from anywhere else.
    |
    */
    'endpoints' => [
        'ts' => env('PRISM_AGENT_TS_URL', 'http://127.0.0.1:7411/mcp'),
        'py' => env('PRISM_AGENT_PY_URL', 'http://127.0.0.1:7412/mcp'),
    ],

    /*
    |---------------------------------------------------------------------------
    | The coordinator
    |---------------------------------------------------------------------------
    |
    | Prism.php reasons about the ecosystem rather than about one port, so it
    | gets a stronger model than the language agents do.
    |
    | max_steps bounds the loop, and the bound is not cosmetic: every step can
    | call a teammate, and a teammate call is billable in that teammate's own
    | account. An unbounded coordinator spends other people's budgets.
    |
    */
    'coordinator' => [
        'provider' => env('PRISM_COORDINATOR_PROVIDER', 'anthropic'),
        'model' => env('PRISM_COORDINATOR_MODEL', 'claude-sonnet-4-5'),
        'max_steps' => (int) env('PRISM_COORDINATOR_MAX_STEPS', 8),
    ],

    /*
    |---------------------------------------------------------------------------
    | Where 0L reports are written
    |---------------------------------------------------------------------------
    |
    | The envelope's shared knowledge directory, so a learning is committed and
    | readable by every agent and human in the workspace rather than trapped in
    | this app's database. Two levels up from the repo root:
    | repos/prism-labs -> the .agi envelope.
    |
    */
    'learnings_path' => env('PRISM_LEARNINGS_PATH', dirname(base_path(), 2).'/.ai/learnings'),

    /*
    |---------------------------------------------------------------------------
    | Timeouts
    |---------------------------------------------------------------------------
    |
    | Two budgets, because one number would either cut off a teammate mid-thought
    | or leave a dead lane looking busy for a minute. A reasoning call is slow by
    | nature; a status call that is slow is a status call that has failed.
    |
    */
    'timeouts' => [
        'status' => (int) env('PRISM_AGENT_STATUS_TIMEOUT', 5),
        'work' => (int) env('PRISM_AGENT_WORK_TIMEOUT', 120),
    ],
];
