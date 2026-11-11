<?php

declare(strict_types=1);

return [

    /*
    | This ledger's origin: the `<origin>` in `CC-<origin>-0007`. One per lab,
    | so one Overwatch can merge several ledgers (fancy, prism, ...).
    */
    'origin' => 'prism',

    // Where a petition tells the agent it can read the Change.
    'lab_url' => env('COUNCIL_LAB_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/council'),

    /*
    | Who owns the ledger: login emails, comma-separated in COUNCIL_OWNERS,
    | compared trimmed and case-insensitively. Read ONLY by the opt-in owners
    | authorizer (`Council::authorizeOwners()`), which gives these users every
    | ability (view, owner, feed) and everyone else none. A host that registers
    | its own hook with `Council::authorizeUsing()` does not read this list.
    |
    | Empty means NOBODY. There is no "any signed-in user" fallback: on a host
    | with open registration, signing in proves nothing about who you are. For
    | the same reason, list an email only once its account exists on the host;
    | an unclaimed owner email is one anybody could register.
    */
    'owners' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('COUNCIL_OWNERS', ''))),
        static fn (string $email): bool => $email !== '',
    )),

    /*
    | The way back to the host app, drawn at the top of both lab pages.
    | An empty URL hides the link.
    */
    'host_url' => '/lab',
    'host_label' => 'Back to Prism Lab',

    // Round deadlines, in hours. Silent required voters get ONE extension.
    'deadlines' => [
        'round1_hours' => 72,
        'round2_hours' => 48,
        'extension_hours' => 24,
    ],

    // Defaults preserve one blocking conflict at weight 1. Only the verified
    // human owner can change member weights or the recorded threshold.
    'tally' => [
        'blocking_threshold' => (int) env('COUNCIL_BLOCKING_THRESHOLD', 1),
        'max_threshold' => (int) env('COUNCIL_MAX_BLOCKING_THRESHOLD', 1000),
    ],
    'weights' => ['max' => (int) env('COUNCIL_MAX_WEIGHT', 1000)],
    'members' => ['project_name_max' => 200, 'project_description_max' => 5000],

    // This host's publisher scope replaces the entire package default rule.
    // Omitted ecosystems accept nothing; native validation refuses bad rules.
    'census' => [
        'publisher' => 'Prism',
        'first_party' => [
            'pypi' => ['patterns' => ['prism-ai-*', 'prism-conformance']],
            'composer' => ['patterns' => ['particle-academy/prism*']],
            'npm' => ['patterns' => ['@particle-academy/prism*']],
        ],
    ],

    // A Change with no required voters is approved_unopposed only when every
    // external consumer's census is younger than this; otherwise it escalates.
    'census_max_age_days' => 30,

    /*
    | Who may initiate straight into round 1. Everything else lands in
    | `awaiting_owner` (HITL) with a pros/cons summary, and no petition goes out
    | until the owner approves. A GitHub issue counts as whitelisted only when
    | its AUTHOR is a listed login; every other issue goes to HITL.
    |
    | agents: literal Genie addresses (plus any consumer row marked whitelisted)
    | github_authors: literal logins; github_authors_file: a trusted-authors.txt
    |
    | Owner decisions are not configured here: they go through the lab UI only,
    | behind the host's authorization hook (the `owner` ability). No artisan
    | command can record one.
    */
    'whitelist' => [
        'agents' => array_filter(explode(',', (string) env('COUNCIL_WHITELIST_AGENTS', ''))),
        'github_authors' => ['wishborn'],
        'github_authors_file' => env('COUNCIL_TRUSTED_AUTHORS_FILE'),
    ],

    /*
    | Ed25519 signing for decision records and the Overwatch webhook, through
    | PHP's built-in sodium. `php artisan council:keygen` prints a pair.
    | secret_key: base64 of the 64-byte secret key. public_keys: key_id => base64
    | of a 32-byte public key (every key a record may be verified against).
    */
    'signing' => [
        'secret_key' => env('COUNCIL_SIGNING_KEY'),
        'public_keys' => [],
    ],

    // The detector the `council:detect` command runs: the SAME check.mjs CI
    // runs, never a PHP mirror of it.
    'detector' => [
        'node' => env('COUNCIL_NODE', 'node'),
        'script' => null, // null: the copy shipped in this package
    ],

    // Overwatch push. Off until a URL is set. The pull feed is always available
    // to a principal holding the `feed` ability.
    'feed' => [
        'webhook_url' => env('COUNCIL_FEED_WEBHOOK_URL'),
        'max_attempts' => 8,
        'batch' => 200,
    ],

    /*
    | Owner events (initiation approved/rejected, override, revoke, a round's
    | tally closed, escalation) to Genie's app inbox, after commit. Off by
    | default. Only the switch and the address live here: GENIE_INBOX_URL and
    | GENIE_INBOX_TOKEN, which Genie gives a hosted site, are read with getenv()
    | at send time, because config:cache would freeze a token Genie rotates.
    | `to` empty: Genie's default agent for the workspace. The notice links to
    | `lab_url` when that is set.
    */
    'notify' => [
        'genie' => filter_var(env('COUNCIL_NOTIFY_GENIE', false), FILTER_VALIDATE_BOOL),
        'to' => 'prism@prism',
    ],

    // The optional routes: JSON API under {prefix}/api/v1 and the lab pages at
    // {prefix}. Every route asks the host's authorization hook (default: deny).
    'routes' => [
        'enabled' => false,
        'prefix' => 'council',
        'middleware' => ['web', 'auth'],
        'max_body_bytes' => 65536,
    ],

    'pages' => [
        // Lab pages poll slowly: a lab is served by ONE php-cgi worker.
        'poll_ms' => 15000,
        // Where `vendor:publish --tag=council-pages` copies the TSX. Prism's
        // lab globs ./pages/**, fancy-labs globs ./Pages/**.
        'publish_path' => 'js/pages/Council',
    ],
];
