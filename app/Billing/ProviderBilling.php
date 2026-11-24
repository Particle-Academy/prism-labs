<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The BILLED side of calibration for one provider (G-291).
 *
 * The admin key is read from config at call time, passed only as a
 * SensitiveParameter, and sent only to this provider's own billing endpoint in
 * its auth header. It is never stored on the object, so a serialised client,
 * a dumped object or a job payload cannot carry it.
 */
abstract class ProviderBilling
{
    abstract public function provider(): string;

    /** Why this provider cannot be reconciled at all, or null when it can. */
    abstract public function notConfigured(): ?string;

    /** Billed usage and cost for [from, to), UTC day buckets. */
    abstract public function fetch(CarbonImmutable $from, CarbonImmutable $to): BilledPeriod;

    /** @param list<array{string, string}> $query */
    protected static function queryString(array $query): string
    {
        return implode('&', array_map(fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $query));
    }

    /**
     * Every page of a cursor-paginated endpoint.
     *
     * @param  list<array{string, string}>  $query
     * @return list<array<string, mixed>>
     */
    protected function pages(#[SensitiveParameter] string $key, string $url, array $query): array
    {
        $pages = [];
        $cursor = null;
        $max = max(1, (int) config('lab.billing.max_pages', 50));
        for ($i = 0; $i < $max; $i++) {
            $params = $cursor === null ? $query : [...$query, ['page', $cursor]];
            $json = $this->get($key, $url.'?'.self::queryString($params));
            $pages[] = $json;
            if (($json['has_more'] ?? false) !== true) {
                return $pages;
            }
            $cursor = $json['next_page'] ?? null;
            if (! is_string($cursor) || $cursor === '') {
                throw new BillingUnavailable('malformed_pagination');
            }
        }

        throw new BillingUnavailable('too_many_pages');
    }

    /** @return array<string, mixed> */
    private function get(#[SensitiveParameter] string $key, string $url): array
    {
        $timeout = (int) config('lab.billing.timeout_seconds', 20);
        try {
            $response = $this->authorise(Http::timeout($timeout)->connectTimeout($timeout)->acceptJson(), $key)
                ->withMiddleware(SameOriginRedirects::middleware())
                ->get($url);
        } catch (BillingUnavailable $refused) {
            throw $refused;
        } catch (ConnectionException) {
            throw new BillingUnavailable('connection_failed');
        } catch (Throwable) {
            throw new BillingUnavailable('request_failed');
        }

        return $this->decode($response);
    }

    abstract protected function authorise(PendingRequest $request, #[SensitiveParameter] string $key): PendingRequest;

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw new BillingUnavailable('http_'.$response->status());
        }
        try {
            $json = $response->json();
        } catch (Throwable) {
            $json = null;
        }
        if (! is_array($json) || ! is_array($json['data'] ?? null)) {
            throw new BillingUnavailable('malformed_response');
        }

        return $json;
    }

    protected static function key(string $config): ?string
    {
        $key = config($config);

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /** @return list<string> */
    protected static function ids(string $config): array
    {
        return array_values(array_filter((array) config($config, []), fn (mixed $id): bool => is_string($id) && $id !== ''));
    }

    protected static function int(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
