<?php

declare(strict_types=1);

return [
    'browser' => [
        'enabled' => env('PRISM_BROWSER_ENABLED', true),
    ],
    'human_plus' => [
        'enabled' => env('PRISM_HUMAN_PLUS_ENABLED', true),
        'allowed_relay_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRISM_HUMAN_PLUS_RELAY_HOSTS', ''))))),
        'allowed_relay_ports' => array_map('intval', array_filter(array_map('trim', explode(',', (string) env('PRISM_HUMAN_PLUS_RELAY_PORTS', '443'))))),
        'allowed_tools' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRISM_HUMAN_PLUS_TOOLS', ''))))),
        'approval_tools' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRISM_HUMAN_PLUS_APPROVAL_TOOLS', ''))))),
        'egress_proxy' => env('PRISM_HUMAN_PLUS_EGRESS_PROXY'),
        'allow_unverified_egress' => env('PRISM_HUMAN_PLUS_ALLOW_UNVERIFIED_EGRESS', false),
        'local_resolve' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRISM_HUMAN_PLUS_LOCAL_RESOLVE', ''))))),
        'auth_mode' => env('PRISM_HUMAN_PLUS_AUTH_MODE', 'query'),
        'fixture' => [
            'relay_url' => env('PRISM_HUMAN_PLUS_FIXTURE_RELAY_URL', 'https://prism-human-relay.gen'),
            'session_id' => env('PRISM_HUMAN_PLUS_FIXTURE_SESSION', 'prism-lab-fixture'),
            'token' => env('PRISM_HUMAN_PLUS_FIXTURE_TOKEN'),
        ],
    ],
];
