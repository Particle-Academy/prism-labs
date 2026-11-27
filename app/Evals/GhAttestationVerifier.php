<?php

declare(strict_types=1);

namespace App\Evals;

use Symfony\Component\Process\Process;

/**
 * Verifies with `gh attestation verify`, under the operator's own gh login.
 *
 * The signer is pinned to this repository's harness-bench.yml, so a file
 * signed by any other workflow, fork or repository is refused. The command and
 * every argument are constants or the resolved path: nothing from the file's
 * contents reaches the command line.
 */
final class GhAttestationVerifier implements AttestationVerifier
{
    public const REPOSITORY = 'Particle-Academy/prism-labs';

    public const SIGNER_WORKFLOW = 'Particle-Academy/prism-labs/.github/workflows/harness-bench.yml';

    public function verify(string $absolutePath): void
    {
        $process = new Process(['gh', 'attestation', 'verify', $absolutePath, '--repo', self::REPOSITORY,
            '--signer-workflow', self::SIGNER_WORKFLOW, '--format', 'json'], null, null, null, 120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new EvalImportRefused('The results file has no valid attestation from harness-bench.yml (gh attestation verify exited '.$process->getExitCode().').');
        }
    }
}
