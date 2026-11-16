<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;

/**
 * OpenAI organization usage and costs, read with an ADMIN key.
 *
 * Verified against OpenAI's OpenAPI spec (2026-11-15):
 *  - GET /v1/organization/usage/completions: start_time/end_time (unix
 *    seconds, end exclusive), bucket_width=1d (limit max 31), group_by and
 *    api_key_ids as arrays, cursor `page` = previous `next_page`. Each bucket
 *    holds results with input_tokens (INCLUDING cached), input_cached_tokens,
 *    output_tokens, input/output_audio_tokens, num_model_requests, model.
 *  - GET /v1/organization/costs: same window, bucket_width only 1d, limit up
 *    to 180, group_by only project_id / line_item, filter only project_ids.
 *    It can neither be grouped by model nor filtered by API key, so cost is
 *    compared per provider-day, scoped by the Lab's project IDs.
 *
 * Arrays are sent as repeated keys (`group_by=model`), the form OpenAPI's
 * default explode style describes.
 */
final class OpenAiBilling extends ProviderBilling
{
    public function provider(): string
    {
        return 'openai';
    }

    public function notConfigured(): ?string
    {
        return match (true) {
            self::key('lab.billing.openai_admin_key') === null => 'admin_key_missing',
            self::ids('lab.billing.openai_api_key_ids') === [] => 'api_key_ids_missing',
            default => null,
        };
    }

    public function fetch(CarbonImmutable $from, CarbonImmutable $to): BilledPeriod
    {
        $key = self::key('lab.billing.openai_admin_key');
        if ($key === null) {
            throw new BillingUnavailable('admin_key_missing');
        }
        $base = rtrim((string) config('lab.billing.openai_url'), '/');
        $window = [['start_time', (string) $from->getTimestamp()], ['end_time', (string) $to->getTimestamp()], ['bucket_width', '1d']];

        $tokens = null;
        $tokensFailure = null;
        try {
            $query = [...$window, ['group_by', 'model'], ['limit', '31']];
            foreach (self::ids('lab.billing.openai_api_key_ids') as $id) {
                $query[] = ['api_key_ids', $id];
            }
            $tokens = $this->tokens($this->pages($key, $base.'/organization/usage/completions', $query));
        } catch (BillingUnavailable $failure) {
            $tokensFailure = $failure->reason;
        }

        $projects = self::ids('lab.billing.openai_project_ids');
        $cost = null;
        $costStatus = null;
        $costReason = null;
        if ($projects === []) {
            $costStatus = 'not_configured';
            $costReason = 'project_ids_missing';
        } else {
            try {
                $query = [...$window, ['limit', '180']];
                foreach ($projects as $id) {
                    $query[] = ['project_ids', $id];
                }
                $cost = $this->cost($this->pages($key, $base.'/organization/costs', $query));
            } catch (BillingUnavailable $failure) {
                $costStatus = 'unknown';
                $costReason = $failure->reason;
            }
        }

        return new BilledPeriod($tokens, $tokensFailure, $cost, $costStatus, $costReason, costByModel: false);
    }

    protected function authorise(PendingRequest $request, #[SensitiveParameter] string $key): PendingRequest
    {
        return $request->withToken($key);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, array<string, array<string, int>>>
     */
    private function tokens(array $pages): array
    {
        $days = [];
        foreach ($this->buckets($pages) as [$day, $results]) {
            $days[$day] ??= [];
            foreach ($results as $result) {
                if (! is_array($result) || ! is_int($result['input_tokens'] ?? null) || ! is_int($result['output_tokens'] ?? null)) {
                    throw new BillingUnavailable('malformed_response');
                }
                $model = is_string($result['model'] ?? null) && $result['model'] !== '' ? $result['model'] : BilledPeriod::UNATTRIBUTED_MODEL;
                $cached = self::int($result['input_cached_tokens'] ?? 0);
                $row = $days[$day][$model] ?? ['input' => 0, 'cached_input' => 0, 'cache_write' => 0, 'output' => 0, 'audio' => 0, 'requests' => 0];
                // input_tokens INCLUDES cached tokens; Prism records them apart.
                $row['input'] += max(0, self::int($result['input_tokens']) - $cached);
                $row['cached_input'] += $cached;
                $row['output'] += self::int($result['output_tokens']);
                $row['audio'] += self::int($result['input_audio_tokens'] ?? 0) + self::int($result['output_audio_tokens'] ?? 0);
                $row['requests'] += self::int($result['num_model_requests'] ?? 0);
                $days[$day][$model] = $row;
            }
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
        foreach ($this->buckets($pages) as [$day, $results]) {
            $total = $days[$day]['*'] ?? 0.0;
            foreach ($results as $result) {
                $value = $result['amount']['value'] ?? null;
                $currency = $result['amount']['currency'] ?? null;
                if ((! is_int($value) && ! is_float($value)) || ! is_string($currency)) {
                    throw new BillingUnavailable('malformed_response');
                }
                if (strtolower($currency) !== 'usd') {
                    throw new BillingUnavailable('unexpected_currency');
                }
                $total += (float) $value;
            }
            $days[$day] = ['*' => $total];
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{string, list<mixed>}>
     */
    private function buckets(array $pages): array
    {
        $buckets = [];
        foreach ($pages as $page) {
            foreach ($page['data'] as $bucket) {
                // The spec names the list `result`; its own examples say `results`.
                $results = $bucket['results'] ?? $bucket['result'] ?? null;
                if (! is_array($bucket) || ! is_int($bucket['start_time'] ?? null) || ! is_array($results)) {
                    throw new BillingUnavailable('malformed_response');
                }
                $buckets[] = [CarbonImmutable::createFromTimestampUTC($bucket['start_time'])->toDateString(), array_values($results)];
            }
        }

        return $buckets;
    }
}
