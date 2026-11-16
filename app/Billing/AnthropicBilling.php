<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;
use Throwable;

/**
 * Anthropic's Usage and Cost Analytics API (beta), read with an Admin API key.
 *
 * From platform.claude.com/docs/en/manage-claude/usage-cost-analytics-api
 * (read 2026-11-15):
 *  - GET /v1/organizations/analytics/usage and /analytics/cost, headers
 *    `anthropic-beta: analytics-usage-cost-2026-08-24`, `anthropic-version`,
 *    `x-api-key`.
 *  - starting_at (inclusive) / ending_at (exclusive) RFC 3339; ending_at is
 *    required with time_bucket_width=1d; at most 31 days per request.
 *  - Bracket arrays: group_by[]=model, api_key_ids[]=apikey_... (OR within).
 *  - Rows: starting_at, group.model (null on e.g. code execution), usage:
 *    uncached_input_tokens, cache_creation.ephemeral_5m/1h_input_tokens,
 *    cache_read_input_tokens, output_tokens, requests. Cost rows: cost.amount
 *    — a decimal STRING in CENTS ("41280.000000" is $412.80) after discounts —
 *    and list_amount at list price, currency "USD".
 *  - limit is rows per page (1..1000); has_more / next_page, passed back as
 *    `page` (the field names carry over from the legacy API, per the doc).
 *  - data_refreshed_at: usage after it is missing or partial; null with empty
 *    data when nothing is ready. A day ending after it is not yet billed.
 */
final class AnthropicBilling extends ProviderBilling
{
    public function provider(): string
    {
        return 'anthropic';
    }

    public function notConfigured(): ?string
    {
        return match (true) {
            self::key('lab.billing.anthropic_admin_key') === null => 'admin_key_missing',
            self::ids('lab.billing.anthropic_api_key_ids') === [] => 'api_key_ids_missing',
            default => null,
        };
    }

    public function fetch(CarbonImmutable $from, CarbonImmutable $to): BilledPeriod
    {
        $key = self::key('lab.billing.anthropic_admin_key');
        if ($key === null) {
            throw new BillingUnavailable('admin_key_missing');
        }
        $base = rtrim((string) config('lab.billing.anthropic_url'), '/');
        $query = [
            ['starting_at', $from->utc()->format('Y-m-d\TH:i:s\Z')],
            ['ending_at', $to->utc()->format('Y-m-d\TH:i:s\Z')],
            ['time_bucket_width', '1d'],
            ['group_by[]', 'model'],
            ['limit', '1000'],
        ];
        foreach (self::ids('lab.billing.anthropic_api_key_ids') as $id) {
            $query[] = ['api_key_ids[]', $id];
        }

        $fresh = [];
        $tokens = null;
        $tokensFailure = null;
        try {
            $pages = $this->pages($key, $base.'/organizations/analytics/usage', $query);
            $fresh[] = $this->refreshedAt($pages);
            $tokens = $this->tokens($pages);
        } catch (BillingUnavailable $failure) {
            $tokensFailure = $failure->reason;
        }

        $cost = null;
        $costStatus = null;
        $costReason = null;
        try {
            $pages = $this->pages($key, $base.'/organizations/analytics/cost', $query);
            $fresh[] = $this->refreshedAt($pages);
            $cost = $this->cost($pages);
        } catch (BillingUnavailable $failure) {
            $costStatus = 'unknown';
            $costReason = $failure->reason;
        }

        // The older of the two snapshots bounds both: a day is complete only
        // when every figure compared for it is.
        $freshThrough = null;
        if ($fresh !== [] && ! in_array(null, $fresh, true)) {
            $freshThrough = min($fresh);
        }

        return new BilledPeriod($tokens, $tokensFailure, $cost, $costStatus, $costReason,
            costByModel: true, tracksFreshness: true, freshThrough: $freshThrough);
    }

    protected function authorise(PendingRequest $request, #[SensitiveParameter] string $key): PendingRequest
    {
        return $request->withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => (string) config('lab.billing.anthropic_version'),
            'anthropic-beta' => (string) config('lab.billing.anthropic_beta'),
        ]);
    }

    /** @param list<array<string, mixed>> $pages */
    private function refreshedAt(array $pages): ?CarbonImmutable
    {
        $value = $pages[0]['data_refreshed_at'] ?? null;
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new BillingUnavailable('malformed_response');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, array<string, array<string, int>>>
     */
    private function tokens(array $pages): array
    {
        $days = [];
        foreach ($this->rows($pages) as [$day, $model, $row]) {
            $usage = $row['usage'] ?? null;
            if (! is_array($usage) || ! is_int($usage['output_tokens'] ?? null)) {
                throw new BillingUnavailable('malformed_response');
            }
            $creation = is_array($usage['cache_creation'] ?? null) ? $usage['cache_creation'] : [];
            $entry = $days[$day][$model] ?? ['input' => 0, 'cached_input' => 0, 'cache_write' => 0, 'output' => 0, 'audio' => 0, 'requests' => 0];
            $entry['input'] += self::int($usage['uncached_input_tokens'] ?? 0);
            $entry['cached_input'] += self::int($usage['cache_read_input_tokens'] ?? 0);
            $entry['cache_write'] += self::int($creation['ephemeral_5m_input_tokens'] ?? 0) + self::int($creation['ephemeral_1h_input_tokens'] ?? 0);
            $entry['output'] += self::int($usage['output_tokens']);
            $entry['requests'] += self::int($usage['requests'] ?? 0);
            $days[$day][$model] = $entry;
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, array<string, float>>
     */
    private function cost(array $pages): array
    {
        $days = [];
        foreach ($this->rows($pages) as [$day, $model, $row]) {
            $amount = $row['cost']['amount'] ?? null;
            $currency = $row['cost']['currency'] ?? null;
            if (! is_string($amount) || ! is_numeric($amount) || ! is_string($currency)) {
                throw new BillingUnavailable('malformed_response');
            }
            if (strtoupper($currency) !== 'USD') {
                throw new BillingUnavailable('unexpected_currency');
            }
            // Cents, as a decimal string.
            $days[$day][$model] = ($days[$day][$model] ?? 0.0) + ((float) $amount) / 100;
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function rows(array $pages): array
    {
        $rows = [];
        foreach ($pages as $page) {
            foreach ($page['data'] as $row) {
                if (! is_array($row) || ! is_string($row['starting_at'] ?? null)) {
                    throw new BillingUnavailable('malformed_response');
                }
                try {
                    $day = CarbonImmutable::parse($row['starting_at'])->utc()->toDateString();
                } catch (Throwable) {
                    throw new BillingUnavailable('malformed_response');
                }
                $model = $row['group']['model'] ?? null;
                $rows[] = [$day, is_string($model) && $model !== '' ? $model : BilledPeriod::UNATTRIBUTED_MODEL, $row];
            }
        }

        return $rows;
    }
}
