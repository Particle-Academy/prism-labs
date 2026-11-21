<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DocsLab\Certificates;
use App\Models\DocsLabRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * G-316: certify a prism-agent-docs version from a passed live run, or verify
 * the certificate chain. Spends nothing; it only reads recorded runs.
 *
 *   php artisan lab:docs-certify                 # the latest passed live run
 *   php artisan lab:docs-certify --run=<run id>
 *   php artisan lab:docs-certify --verify
 */
final class DocsCertifyCommand extends Command
{
    protected $signature = 'lab:docs-certify
        {--run= : The live run to certify; defaults to the latest passed live run}
        {--verify : Check every certificate\'s hash and chain link instead}';

    protected $description = 'Issue (or verify) a hash-chained certificate for prism-agent-docs from a passed live lab run';

    public function handle(Certificates $certificates): int
    {
        if ($this->option('verify')) {
            $problems = $certificates->verify();
            foreach ($problems as $problem) {
                $this->error('  '.$problem);
            }
            $this->line($problems === [] ? '  Certificate chain intact.' : '  Certificate chain BROKEN.');

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $id = $this->option('run');
        $run = is_string($id) && Str::isUuid($id)
            ? DocsLabRun::query()->find($id)
            : DocsLabRun::query()->where('lane', 'live')->where('status', 'passed')->orderByDesc('started_at')->first();
        if (! $run instanceof DocsLabRun) {
            $this->error('  No passed live run to certify. Run `php artisan lab:docs-run --lane=live --judge` with an approved GO first.');

            return self::FAILURE;
        }
        try {
            $certificate = $certificates->issue($run);
        } catch (RuntimeException $e) {
            $this->error('  Refused: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('  Certificate %d: %s %s, hash %s', $certificate->sequence, $certificate->package, $certificate->version, $certificate->hash));

        return self::SUCCESS;
    }
}
