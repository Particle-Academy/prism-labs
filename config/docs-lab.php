<?php

declare(strict_types=1);

// G-316: the docs-agent lab (/lab/docs, lab:docs-run, lab:docs-certify).
// Artefacts are the real .docx files a lane wrote and re-read, kept so a run's
// verdict can be checked against the file rather than taken on trust.
return [
    'artefacts' => env('DOCS_LAB_ARTEFACTS', storage_path('app/docs-lab')),
];
