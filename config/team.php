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
        'model' => env('PRISM_COORDINATOR_MODEL', 'claude-opus-5'),
        'max_steps' => (int) env('PRISM_COORDINATOR_MAX_STEPS', 8),

        // The default HTTP timeout is 30s, and a coordinator step routinely
        // outlasts it: each one can delegate to a teammate that makes its own
        // model call, or search the web and wait on a provider that searches
        // while it answers. Thirty seconds was enough while the only tools were
        // local; it stopped being enough the moment the team could reach out.
        'timeout' => (int) env('PRISM_COORDINATOR_TIMEOUT', 180),
    ],

    /*
    |---------------------------------------------------------------------------
    | Research
    |---------------------------------------------------------------------------
    |
    | The team's window on the world outside this ecosystem. Perplexity, because
    | it searches while it answers and returns citations rather than asking you
    | to trust a training cut-off.
    |
    | This is not a side feature. The Lab exists to keep the ecosystem ahead of
    | what else is being built, and a team that can only look inward can only
    | report on itself.
    |
    */
    'research' => [
        'model' => env('PRISM_RESEARCH_MODEL', 'sonar'),
        'max_tokens' => (int) env('PRISM_RESEARCH_MAX_TOKENS', 1200),
        'max_results' => (int) env('PRISM_RESEARCH_MAX_RESULTS', 6),
    ],

    /*
    |---------------------------------------------------------------------------
    | Nudging a human's agent
    |---------------------------------------------------------------------------
    |
    | The board can hand a 0L to the coding agent working in this workspace, over
    | Genie's own MCP server — reached with prism-mcp's client, so the package
    | carries real traffic rather than only its own tests.
    |
    | Addressed to the workspace CHANNEL rather than an agent id: an agent id is
    | per-session and a button wired to one would silently stop working the next
    | time that session ends.
    |
    */
    /*
    |---------------------------------------------------------------------------
    | Fact-checker
    |---------------------------------------------------------------------------
    |
    | prism-parity's factcheck.mjs, which verifies that documentation across the
    | ecosystem still agrees with the code. The same script CI runs — one
    | implementation, so the Lab and CI cannot reach different conclusions.
    |
    | The default assumes the envelope layout, with prism-parity checked out
    | beside this app.
    |
    */
    'factcheck' => [
        'script' => env('PRISM_FACTCHECK_SCRIPT', base_path('../prism-parity/tools/factcheck.mjs')),
        'timeout' => (int) env('PRISM_FACTCHECK_TIMEOUT', 180),
    ],

    'nudge' => [
        'endpoint' => env('GENIE_MCP_URL'),
        'channel' => env('GENIE_NUDGE_CHANNEL', 'general'),

        // Genie will not guess which terminal is acting when a workspace has
        // several.
        //
        // The TERMINAL is stored rather than the agent id it holds. An agent id
        // is persisted into the terminal's spec, so it survives a restart — but
        // a replaced terminal mints a new one, and a send to the old id fails
        // without saying so. The agent is resolved from this at call time.
        'terminal' => env('GENIE_TERMINAL_ID'),
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
