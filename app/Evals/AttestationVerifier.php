<?php

declare(strict_types=1);

namespace App\Evals;

/** Does this file carry a valid GitHub attestation from harness-bench.yml? */
interface AttestationVerifier
{
    /** Throws EvalImportRefused when it does not. */
    public function verify(string $absolutePath): void;
}
