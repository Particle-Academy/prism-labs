<?php

declare(strict_types=1);

namespace App\Drift;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Our pull requests to upstream prism-php/prism, and how they are landing.
 *
 * Public, unauthenticated GitHub issue search, one query per configured login,
 * each answer cached 15 minutes (search allows 10 unauthenticated calls a
 * minute). If ANY login's search fails the whole panel is unknown: counting
 * the PRs we could read would undercount and read as fewer open PRs than
 * there are. Never zero for "could not look".
 *
 * State: `merged` when pull_request.merged_at is set, `closed` (unmerged) when
 * the PR is closed without it, otherwise `open`.
 */
class UpstreamPullRequests
{
    /** A GitHub login: alphanumerics and single hyphens, at most 39 characters. */
    private const LOGIN = '/\A[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}\z/';

    private const PER_PAGE = 100;

    public const SPARK_WEEKS = 12;

    /** @return array<string, mixed> */
    public function read(CarbonImmutable $now): array
    {
        $repo = (string) config('lab.upstream.repo');
        // A login that is not a login is dropped rather than spliced into the
        // search query, where it could widen the search to someone else's PRs.
        $authors = array_values(array_unique(array_filter((array) config('lab.upstream.pr_authors', []),
            fn (mixed $login): bool => is_string($login) && preg_match(self::LOGIN, $login) === 1)));

        $unknown = ['readable' => false, 'repo' => $repo, 'authors' => $authors, 'counts' => null,
            'mergeRate' => null, 'mergedPerWeek' => [], 'lowerBound' => false, 'pulls' => []];
        if ($authors === [] || preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $repo) !== 1) {
            return $unknown;
        }

        $items = [];
        $lowerBound = false;
        foreach ($authors as $login) {
            $answer = $this->search($repo, $login);
            if ($answer === null) {
                return $unknown;
            }
            $lowerBound = $lowerBound || $answer['incomplete'];
            foreach ($answer['items'] as $item) {
                $items[$item['number']] = $item;
            }
        }
        krsort($items);

        $stale = (int) config('lab.upstream.pr_stale_days', 30);
        $pulls = [];
        $counts = ['open' => 0, 'merged' => 0, 'closed' => 0];
        foreach ($items as $item) {
            $counts[$item['state']]++;
            $created = CarbonImmutable::parse($item['createdAt']);
            $updated = CarbonImmutable::parse($item['updatedAt']);
            $age = (int) floor($created->diffInDays($now, true));
            $pulls[] = [...$item,
                'ageDays' => $age,
                'idleDays' => (int) floor($updated->diffInDays($now, true)),
                'stale' => $item['state'] === 'open' && $age > $stale,
            ];
        }
        $decided = $counts['merged'] + $counts['closed'];

        return [
            'readable' => true,
            'repo' => $repo,
            'authors' => $authors,
            'counts' => $counts,
            // Of the PRs upstream has decided, how many it took. Null until one is decided.
            'mergeRate' => $decided === 0 ? null : round($counts['merged'] / $decided * 100, 1),
            'mergedPerWeek' => $this->mergedPerWeek($pulls, $now),
            'lowerBound' => $lowerBound,
            'pulls' => $pulls,
        ];
    }

    /**
     * Cumulative merged count at the end of each of the last SPARK_WEEKS weeks.
     *
     * @param  list<array<string, mixed>>  $pulls
     * @return list<int>
     */
    private function mergedPerWeek(array $pulls, CarbonImmutable $now): array
    {
        $merged = array_values(array_filter(array_map(fn (array $p): ?CarbonImmutable => $p['mergedAt'] === null ? null : CarbonImmutable::parse($p['mergedAt']), $pulls)));
        $series = [];
        for ($i = self::SPARK_WEEKS - 1; $i >= 0; $i--) {
            $end = $now->subWeeks($i);
            $series[] = count(array_filter($merged, fn (CarbonImmutable $at): bool => $at->lte($end)));
        }

        return $series;
    }

    /** @return array{items: list<array<string, mixed>>, incomplete: bool}|null */
    private function search(string $repo, string $login): ?array
    {
        // Both parts were checked against strict patterns in read(), so they
        // can be spliced verbatim; `+` is the query's own separator.
        $url = "https://api.github.com/search/issues?q=repo:{$repo}+is:pr+author:{$login}&per_page=".self::PER_PAGE;
        $key = 'lab.drift.upstream-prs.'.sha1($url);
        $hit = Cache::get($key);
        if (is_array($hit) && array_key_exists('value', $hit)) {
            return is_array($hit['value']) ? $hit['value'] : null;
        }

        $value = null;
        try {
            $timeout = (int) config('lab.upstream.timeout_seconds', 10);
            $response = Http::timeout($timeout)->connectTimeout($timeout)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->get($url);
            if ($response->successful() && is_array($response->json())) {
                $value = $this->extract($response->json(), $repo);
            }
        } catch (Throwable) {
            $value = null;
        }

        Cache::put($key, ['value' => $value], $value === null
            ? now()->addSeconds((int) config('lab.upstream.failure_cache_seconds', 60))
            : now()->addMinutes((int) config('lab.upstream.cache_minutes', 15)));

        return $value;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{items: list<array<string, mixed>>, incomplete: bool}|null
     */
    private function extract(array $json, string $repo): ?array
    {
        if (! is_array($json['items'] ?? null) || ! is_int($json['total_count'] ?? null)) {
            return null;
        }

        $items = [];
        foreach ($json['items'] as $item) {
            // Search returns issues too; only a PR carries `pull_request`.
            if (! is_array($item) || ! is_array($item['pull_request'] ?? null)) {
                continue;
            }
            if (! is_int($item['number'] ?? null) || ! is_string($item['title'] ?? null) || ! in_array($item['state'] ?? null, ['open', 'closed'], true)
                || ! is_string($item['created_at'] ?? null) || ! is_string($item['updated_at'] ?? null) || ! is_string($item['html_url'] ?? null)) {
                return null;
            }
            $mergedAt = $item['pull_request']['merged_at'] ?? null;
            $items[] = [
                'number' => $item['number'],
                'title' => $item['title'],
                'state' => is_string($mergedAt) ? 'merged' : ($item['state'] === 'closed' ? 'closed' : 'open'),
                'createdAt' => $item['created_at'],
                'updatedAt' => $item['updated_at'],
                'mergedAt' => is_string($mergedAt) ? $mergedAt : null,
                // Only ever a link to this repo's PRs, never an arbitrary URL from the payload.
                'url' => str_starts_with($item['html_url'], "https://github.com/{$repo}/pull/") ? $item['html_url'] : null,
            ];
        }

        return [
            'items' => $items,
            // More than one page of results, or GitHub says it timed out: a lower bound.
            'incomplete' => $json['total_count'] > self::PER_PAGE || ($json['incomplete_results'] ?? false) === true,
        ];
    }
}
