<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Props for the "Recorded vs billed" panel on /lab/drift (G-291 part 3).
 *
 * Built ONLY from the reconciliation table and the providers' notConfigured()
 * answer. No key, no config value beyond thresholds, and no upstream body ever
 * reaches these props. A provider that is not configured NOW reads
 * not_configured whatever older rows say; a day with no reconciliation row is
 * unknown, never zero.
 *
 * Bands: red = flagged (over BOTH the percent threshold and the floor); amber
 * = within threshold but over half of both (approaching); green otherwise.
 */
final class BillingPanel
{
    public function __construct(private readonly BillingReconciler $reconciler) {}

    /** @return array<string, mixed> */
    public function read(CarbonImmutable $now): array
    {
        $days = BillingReconciler::days($now);
        $rows = $this->rows($days);

        $providers = [];
        foreach ($this->reconciler->providers() as $billing) {
            $provider = $billing->provider();
            $missing = $billing->notConfigured();
            $series = [];
            foreach ($days as $day) {
                if ($missing !== null) {
                    $cost = $tokens = ['status' => 'not_configured', 'reason' => $missing];
                } else {
                    $cost = $rows === null ? ['status' => 'unknown', 'reason' => 'reconciliations_unreadable']
                        : ($rows[$provider][$day]['cost'] ?? ['status' => 'unknown', 'reason' => 'not_reconciled']);
                    $tokens = $rows === null ? ['status' => 'unknown', 'reason' => 'reconciliations_unreadable']
                        : ($rows[$provider][$day]['tokens'] ?? ['status' => 'unknown', 'reason' => 'not_reconciled']);
                }
                $cost = $this->cell($cost, (float) config('lab.billing.floor_usd', 0.05));
                $tokens = $this->cell($tokens, (float) config('lab.billing.floor_tokens', 1000));
                $series[] = ['day' => $day, 'band' => self::worst($cost['band'], $tokens['band']), 'cost' => $cost, 'tokens' => $tokens];
            }
            $providers[] = [
                'provider' => $provider,
                'status' => $missing !== null ? 'not_configured' : self::worst(...array_column($series, 'band')),
                'reason' => $missing,
                'days' => $series,
            ];
        }

        return [
            'thresholdPercent' => (float) config('lab.billing.threshold_percent', 2.0),
            'floorTokens' => (int) config('lab.billing.floor_tokens', 1000),
            'floorUsd' => (float) config('lab.billing.floor_usd', 0.05),
            'graceHours' => (int) config('lab.billing.grace_hours', 24),
            'providers' => $providers,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function cell(array $row, float $floor): array
    {
        $status = (string) $row['status'];
        $recorded = isset($row['recorded']) ? (float) $row['recorded'] : null;
        $billed = isset($row['billed']) ? (float) $row['billed'] : null;
        $percent = isset($row['delta_percent']) ? (float) $row['delta_percent'] : null;

        $band = match ($status) {
            'flagged' => 'red',
            'ok' => $this->approaching($recorded, $billed, $percent, $floor) ? 'amber' : 'green',
            'not_configured' => 'not_configured',
            default => 'unknown',
        };

        return [
            'status' => $status, 'band' => $band, 'reason' => $row['reason'] ?? null,
            // Figures only where something was compared.
            'recorded' => in_array($status, ['ok', 'flagged'], true) ? $recorded : null,
            'billed' => in_array($status, ['ok', 'flagged'], true) ? $billed : null,
            'deltaPercent' => in_array($status, ['ok', 'flagged'], true) ? $percent : null,
        ];
    }

    private function approaching(?float $recorded, ?float $billed, ?float $percent, float $floor): bool
    {
        if ($recorded === null || $billed === null) {
            return false;
        }
        $threshold = (float) config('lab.billing.threshold_percent', 2.0);

        return abs($billed - $recorded) > $floor / 2 && ($percent === null || $percent > $threshold / 2);
    }

    /**
     * Red over amber over unknown over green. not_configured counts only when
     * nothing else is there: OpenAI cost without project IDs is not
     * configured, and its compared tokens still colour the day.
     */
    public static function worst(string ...$bands): string
    {
        foreach (['red', 'amber', 'unknown', 'green', 'not_configured'] as $band) {
            if (in_array($band, $bands, true)) {
                return $band;
            }
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $days
     * @return array<string, array<string, array<string, array<string, mixed>>>>|null
     */
    private function rows(array $days): ?array
    {
        try {
            if (! Schema::hasTable('billing_reconciliations')) {
                return null;
            }
            $found = DB::table('billing_reconciliations')
                ->where('model', BillingReconciler::ALL)
                ->whereDate('day', '>=', $days[0])->whereDate('day', '<=', end($days))
                ->get(['provider', 'day', 'metric', 'status', 'reason', 'recorded', 'billed', 'delta_percent']);
        } catch (Throwable) {
            return null;
        }

        $rows = [];
        foreach ($found as $row) {
            $day = substr((string) $row->day, 0, 10);
            $rows[(string) $row->provider][$day][(string) $row->metric] = (array) $row;
        }

        return $rows;
    }
}
