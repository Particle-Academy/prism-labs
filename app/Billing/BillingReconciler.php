<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recorded vs billed, per provider, per model, per closed UTC day (G-291).
 *
 * Tokens and cost are compared separately. A day is flagged only when
 * |billed - recorded| exceeds BOTH `threshold_percent` of the billed figure and
 * the absolute floor. Every row says what it is:
 *   ok              compared, within threshold
 *   flagged         compared, over threshold
 *   not_configured  no admin key / no key-ID scope: nothing was compared
 *   unknown         a fetch failed, billing has not refreshed past the day,
 *                   or the recorded cost is unpriced: nothing was compared
 * A provider with no key is never "ok", and a failed fetch is never zero.
 *
 * Model '*' is the provider's whole day — the row the panel draws.
 */
final class BillingReconciler
{
    public const ALL = '*';

    public function __construct(private readonly OpenAiBilling $openai, private readonly AnthropicBilling $anthropic) {}

    /** @return list<ProviderBilling> */
    public function providers(): array
    {
        return [$this->openai, $this->anthropic];
    }

    /** @return list<string> the UTC days that are closed plus grace, oldest first */
    public static function days(CarbonImmutable $now): array
    {
        $grace = max(0, (int) config('lab.billing.grace_hours', 24));
        $lookback = max(1, (int) config('lab.billing.lookback_days', 14));
        $last = $now->utc()->subHours($grace)->startOfDay()->subDay();

        $days = [];
        for ($i = $lookback - 1; $i >= 0; $i--) {
            $days[] = $last->subDays($i)->toDateString();
        }

        return $days;
    }

    public function run(CarbonImmutable $now): void
    {
        foreach ($this->providers() as $billing) {
            $this->reconcile($billing, $now);
        }
    }

    public function reconcile(ProviderBilling $billing, CarbonImmutable $now): void
    {
        $provider = $billing->provider();
        $days = self::days($now);
        $from = CarbonImmutable::parse($days[0], 'UTC');
        $to = CarbonImmutable::parse(end($days), 'UTC')->addDay();

        $missing = $billing->notConfigured();
        $period = null;
        $failure = null;
        if ($missing === null) {
            try {
                $period = $billing->fetch($from, $to);
            } catch (BillingUnavailable $unavailable) {
                $failure = $unavailable->reason;
            }
        }

        // The recorded side only exists from the recorder's first row on. A day
        // before it has no recorded side at all, so it is unknown, never zero:
        // comparing it would flag every billed day before the Lab could record.
        $recordingSince = DB::table('provider_usage_records')->min('day');
        $recordingSince = $recordingSince === null ? null : CarbonImmutable::parse((string) $recordingSince, 'UTC')->toDateString();

        foreach ($days as $day) {
            $rows = match (true) {
                $missing !== null => $this->blank('not_configured', $missing),
                $period === null => $this->blank('unknown', $failure ?? 'fetch_failed'),
                $recordingSince === null || $day < $recordingSince => $this->blank('unknown', 'not_recording_yet'),
                default => $this->compareDay($provider, $day, $period),
            };
            $this->store($provider, $day, $rows, $now);
        }
    }

    /** @return list<array<string, mixed>> */
    private function blank(string $status, string $reason): array
    {
        return [
            ['model' => self::ALL, 'metric' => 'tokens', 'status' => $status, 'reason' => $reason],
            ['model' => self::ALL, 'metric' => 'cost', 'status' => $status, 'reason' => $reason],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function compareDay(string $provider, string $day, BilledPeriod $period): array
    {
        $end = CarbonImmutable::parse($day, 'UTC')->addDay();
        if ($period->tracksFreshness && ($period->freshThrough === null || $period->freshThrough->lt($end))) {
            return $this->blank('unknown', 'billing_not_refreshed');
        }

        $recorded = $this->recorded($provider, $day);

        return [...$this->tokens($recorded, $period, $day), ...$this->cost($recorded, $period, $day)];
    }

    /**
     * @param  array<string, array<string, mixed>>  $recorded
     * @return list<array<string, mixed>>
     */
    private function tokens(array $recorded, BilledPeriod $period, string $day): array
    {
        if ($period->tokens === null) {
            return [['model' => self::ALL, 'metric' => 'tokens', 'status' => 'unknown', 'reason' => $period->tokensFailure ?? 'fetch_failed']];
        }
        // Absent from a successful answer means billed nothing that day.
        $billed = $period->tokens[$day] ?? [];
        $floor = (float) config('lab.billing.floor_tokens', 1000);

        $rows = [];
        $sumRecorded = 0;
        $sumBilled = 0;
        $models = array_unique([...array_keys($recorded), ...array_keys($billed)]);
        sort($models, SORT_STRING);
        foreach ($models as $model) {
            $r = $recorded[$model] ?? null;
            $b = $billed[$model] ?? null;
            $rTotal = $r === null ? 0 : $r['input'] + $r['cached_input'] + $r['cache_write'] + $r['output'];
            $bTotal = $b === null ? 0 : BilledPeriod::totalTokens($b);
            $sumRecorded += $rTotal;
            $sumBilled += $bTotal;
            $rows[] = ['model' => $model, 'metric' => 'tokens', ...self::compare($rTotal, $bTotal, $floor),
                'reason' => $r === null ? 'billed_not_recorded' : ($b === null ? 'recorded_not_billed' : null),
                'detail' => ['recorded' => $r === null ? null : self::tokenBreakdown($r), 'billed' => $b]];
        }
        $rows[] = ['model' => self::ALL, 'metric' => 'tokens', ...self::compare($sumRecorded, $sumBilled, $floor), 'reason' => null];

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $recorded
     * @return list<array<string, mixed>>
     */
    private function cost(array $recorded, BilledPeriod $period, string $day): array
    {
        if ($period->costStatus !== null || $period->cost === null) {
            return [['model' => self::ALL, 'metric' => 'cost', 'status' => $period->costStatus ?? 'unknown', 'reason' => $period->costReason ?? 'fetch_failed']];
        }
        $billed = $period->cost[$day] ?? [];
        $floor = (float) config('lab.billing.floor_usd', 0.05);

        $rows = [];
        if ($period->costByModel) {
            $models = array_unique([...array_keys($recorded), ...array_keys($billed)]);
            sort($models, SORT_STRING);
            foreach ($models as $model) {
                $rows[] = $this->costRow($model, $recorded[$model] ?? null, $billed[$model] ?? 0.0, $floor);
            }
        }

        $unpriced = 0;
        $total = 0.0;
        foreach ($recorded as $r) {
            $unpriced += $r['unpriced'];
            $total += $r['cost'];
        }
        $all = ['unpriced' => $unpriced, 'cost' => $total, 'calls' => array_sum(array_column($recorded, 'calls'))];
        $rows[] = $this->costRow(self::ALL, $recorded === [] ? null : $all, array_sum($billed), $floor);

        return $rows;
    }

    /** @param array<string, mixed>|null $recorded */
    private function costRow(string $model, ?array $recorded, float $billed, float $floor): array
    {
        if ($recorded !== null && $recorded['unpriced'] > 0) {
            // Harness had no trusted rate for some of these calls: the
            // recorded cost is a lower bound, and comparing it would invent drift.
            return ['model' => $model, 'metric' => 'cost', 'status' => 'unknown', 'reason' => 'recorded_cost_unpriced',
                'billed' => $billed, 'detail' => ['unpriced_generations' => $recorded['unpriced']]];
        }

        return ['model' => $model, 'metric' => 'cost', ...self::compare($recorded['cost'] ?? 0.0, $billed, $floor), 'reason' => null];
    }

    /** @return array{status: string, recorded: float, billed: float, delta: float, delta_percent: float|null} */
    public static function compare(int|float $recorded, int|float $billed, float $floor): array
    {
        $delta = (float) $billed - (float) $recorded;
        $absolute = abs($delta);
        $percent = $billed > 0 ? $absolute / $billed * 100 : ($absolute > 0 ? null : 0.0);
        $overPercent = $percent === null || $percent > (float) config('lab.billing.threshold_percent', 2.0);

        return [
            'status' => $overPercent && $absolute > $floor ? 'flagged' : 'ok',
            'recorded' => (float) $recorded,
            'billed' => (float) $billed,
            'delta' => $delta,
            // Null when billed is zero and recorded is not: infinitely off.
            'delta_percent' => $percent === null ? null : round($percent, 4),
        ];
    }

    /** @return array<string, array<string, mixed>> per model */
    private function recorded(string $provider, string $day): array
    {
        $rows = DB::table('provider_usage_records')
            ->where('provider', $provider)->whereDate('day', $day)
            ->groupBy('model')
            ->selectRaw('model, sum(input_tokens) as input, sum(cached_input_tokens) as cached_input, sum(cache_write_tokens) as cache_write,'
                .' sum(output_tokens) as output, sum(harness_cost_usd) as cost, sum(call_count) as calls,'
                .' sum(case when harness_cost_usd is null then 1 else 0 end) as unpriced')
            ->get();

        $models = [];
        foreach ($rows as $row) {
            $models[(string) $row->model] = [
                'input' => (int) $row->input, 'cached_input' => (int) $row->cached_input,
                'cache_write' => (int) $row->cache_write, 'output' => (int) $row->output,
                'cost' => (float) $row->cost, 'calls' => (int) $row->calls, 'unpriced' => (int) $row->unpriced,
            ];
        }

        return $models;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, int>
     */
    private static function tokenBreakdown(array $r): array
    {
        return ['input' => $r['input'], 'cached_input' => $r['cached_input'], 'cache_write' => $r['cache_write'],
            'output' => $r['output'], 'requests' => $r['calls']];
    }

    /** @param list<array<string, mixed>> $rows */
    private function store(string $provider, string $day, array $rows, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($provider, $day, $rows, $now): void {
            // Billing is revised for days after the fact; each run replaces the day.
            DB::table('billing_reconciliations')->where('provider', $provider)->whereDate('day', $day)->delete();
            foreach ($rows as $row) {
                DB::table('billing_reconciliations')->insert([
                    'provider' => $provider, 'day' => $day, 'model' => $row['model'], 'metric' => $row['metric'],
                    'status' => $row['status'], 'reason' => $row['reason'] ?? null,
                    'recorded' => $row['recorded'] ?? null, 'billed' => $row['billed'] ?? null,
                    'delta' => $row['delta'] ?? null, 'delta_percent' => $row['delta_percent'] ?? null,
                    'detail' => isset($row['detail']) ? json_encode($row['detail'], JSON_THROW_ON_ERROR) : null,
                    'checked_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }
}
