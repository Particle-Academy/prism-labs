<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Drift dashboard
    |---------------------------------------------------------------------------
    |
    | Where the sibling prism-provider-watch checkout lives. The dashboard only
    | READS it: reports/latest.json, reports/archive/*.json, state/*.json, and
    | the date of the last commit that touched state/.
    |
    */
    'provider_watch_path' => env('LAB_PROVIDER_WATCH_PATH', base_path('../prism-provider-watch')),

    /*
    | Our fork and its upstream, compared over the PUBLIC GitHub API with no
    | token. Unauthenticated calls are limited to 60 an hour, so answers are
    | cached; a failed or rate-limited call is cached briefly and shown as
    | "unknown", never as zero.
    */
    'upstream' => [
        'repo' => 'prism-php/prism',
        'fork' => 'Particle-Academy/prism',
        'branch' => 'main',
        'timeout_seconds' => 10,
        'cache_minutes' => 15,
        'failure_cache_seconds' => 60,
        // The watcher reads this many of the newest open upstream items per
        // source (GitHubIssuesSource's default), so a count that reaches it is
        // a lower bound, not the total.
        'watcher_page_size' => 30,
        // Our contributions to upstream, tracked on /lab/drift: PRs opened in
        // `repo` by these GitHub logins (public search, no token).
        'pr_authors' => ['wishborn'],
        // An open PR with no merge after this many days is highlighted.
        'pr_stale_days' => 30,
    ],

    /*
    |---------------------------------------------------------------------------
    | Billing calibration (G-291)
    |---------------------------------------------------------------------------
    |
    | Recorded usage (provider_usage_records, written from Prism's telemetry
    | events) against what each provider BILLED, per UTC day.
    |
    | The admin keys are secrets. They are read here and nowhere else, are sent
    | only to their own provider's billing endpoint in its auth header, and
    | never enter a log, an exception, a job payload or a page prop. With no key
    | a provider is "not configured", never "no drift".
    |
    | The *_ids lists are IDs, not secrets: they scope the billed side to the
    | Lab's own API keys so other usage on the organization does not read as
    | drift. OpenAI's costs endpoint cannot filter by API key or group by model
    | (its OpenAPI spec allows only project_id and line_item), so OpenAI COST is
    | scoped by project and compared per provider-day only; without project IDs
    | it is "not configured". Comma-separated in the environment.
    |
    */
    'billing' => [
        'openai_admin_key' => env('LAB_OPENAI_ADMIN_KEY'),
        'anthropic_admin_key' => env('LAB_ANTHROPIC_ADMIN_KEY'),
        'openai_api_key_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('LAB_OPENAI_API_KEY_IDS', ''))))),
        'openai_project_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('LAB_OPENAI_PROJECT_IDS', ''))))),
        'anthropic_api_key_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('LAB_ANTHROPIC_API_KEY_IDS', ''))))),

        'openai_url' => 'https://api.openai.com/v1',
        'anthropic_url' => 'https://api.anthropic.com/v1',
        'anthropic_version' => env('LAB_ANTHROPIC_BILLING_VERSION', '2026-10-21'),
        'anthropic_beta' => 'analytics-usage-cost-2026-08-24',

        // A UTC day is reconciled only once it has closed AND this long has
        // passed: Anthropic's analytics refresh within 4h and can take 24h,
        // OpenAI's lag is undocumented.
        'grace_hours' => (int) env('LAB_BILLING_GRACE_HOURS', 24),
        'lookback_days' => 14,

        // Flag a day when |billed - recorded| exceeds BOTH of these.
        'threshold_percent' => 2.0,
        'floor_tokens' => (int) env('LAB_BILLING_FLOOR_TOKENS', 1000),
        'floor_usd' => (float) env('LAB_BILLING_FLOOR_USD', 0.05),

        'timeout_seconds' => 20,
        // A cursor that never ends is a failed fetch, not an endless one.
        'max_pages' => 50,
    ],
];
