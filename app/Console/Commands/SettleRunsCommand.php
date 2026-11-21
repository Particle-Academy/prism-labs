<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DocsLab\LiveCostObserver;
use App\Lab\RunAuthority;
use App\Models\LabRunIntent;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcile GOs that finished before runs were settled (G-316): each used
 * docs-lab GO kept its whole cap allocated against the session limit.
 *
 *   php artisan lab:settle-runs            # list what would be released; changes nothing
 *   php artisan lab:settle-runs --apply    # settle them
 *
 * Every settlement goes through RunAuthority::settle(), the same locked,
 * conditional, idempotent path a finishing run takes, so it refuses exactly
 * what a run would: an action not on the ledger, unfinished paid work, a
 * revoked or tampered intent. No SQL of its own, nothing deleted; running it
 * twice releases nothing the second time. Local console only.
 */
final class SettleRunsCommand extends Command
{
    protected $signature = 'lab:settle-runs {--apply : settle them; without it, only list}';

    protected $description = 'Settle finished paid CLI runs at their actual cost and release the rest of their caps';

    public function handle(RunAuthority $authority): int
    {
        if (! app()->environment('local')) {
            $this->error('lab:settle-runs runs on the local Lab only.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $candidates = LabRunIntent::query()->whereIn('action', RunAuthority::SETTLEABLE)
            ->where('status', 'running')->whereNotNull('consumed_at')->whereNull('settled_at')->orderBy('id')->get();
        if ($candidates->isEmpty()) {
            $this->info('Nothing to settle.');

            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($candidates as $intent) {
            $spent = LiveCostObserver::spentFor($intent->id);
            $line = sprintf('%s %s cap $%.6f spent $%.6f release $%.6f', $intent->id, $intent->action,
                $intent->cap_microusd / 1_000_000, $spent / 1_000_000, max(0, $intent->cap_microusd - $spent) / 1_000_000);
            if (! $apply) {
                $this->line('  would settle '.$line);

                continue;
            }
            try {
                $settled = $authority->settle($intent->id);
                $this->info(sprintf('  settled %s (recorded $%.6f)', $line, (int) $settled->settled_microusd / 1_000_000));
            } catch (Throwable $e) {
                $failed++;
                $this->error('  refused '.$line.': '.class_basename($e));
            }
        }
        if (! $apply) {
            $this->line('Dry run: nothing changed. Run with --apply to settle.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
