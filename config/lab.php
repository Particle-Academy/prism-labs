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
    ],
];
