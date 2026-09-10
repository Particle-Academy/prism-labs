<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | What the Overseer is allowed to read
    |---------------------------------------------------------------------------
    |
    | Two shelves, and the split is deliberate.
    |
    | `prism` is the PUBLISHED ecosystem documentation — the same markdown that
    | renders at prism.gen — read out of the sibling `prism-sandbox` checkout.
    | The Lab does not vendor a copy: a copy goes stale silently, and an agent
    | reasoning from stale docs designs a benchmark for a version that no longer
    | exists. Reading the real files means the Overseer is wrong exactly when
    | the docs are wrong, which is a problem someone can see and fix.
    |
    | `plab` is THIS repository's documentation and is not published anywhere.
    | It is about how the Lab works, which is not an instruction to anyone
    | building on Prism — publishing it on the ecosystem's docs site would
    | present the Lab's internals as a pattern to copy.
    |
    | A shelf that is not on disk is not an error. A checkout without the
    | sibling repository is ordinary, and the tools report an empty shelf rather
    | than failing a conversation over documentation.
    |
    */
    'shelves' => [
        'prism' => env('PRISM_DOCS_PATH', base_path('../prism-sandbox/resources/docs')),
        'plab' => env('PLAB_DOCS_PATH', base_path('docs')),
    ],
];
