<?php

declare(strict_types=1);

namespace App\Drift;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How far our fork has drifted from upstream prism-php/prism.
 *
 * Unauthenticated public GitHub REST, deliberately: no token is read, sent or
 * needed. Each answer is cached on its own, so one rate-limited call costs one
 * figure rather than the panel. A failed call yields null — "unknown" on the
 * page — and is cached only briefly so the next look retries it. It is never
 * turned into zero: "0 behind" would read as "no drift".
 */
class UpstreamDrift
{
    /**
     * @param  array<string, mixed>|null  $upstreamWatchState  the watcher's state/upstream.json
     * @return array<string, mixed>
     */
    public function read(?array $upstreamWatchState): array
    {
        $repo = (string) config('lab.upstream.repo');
        $fork = (string) config('lab.upstream.fork');
        $branch = (string) config('lab.upstream.branch');
        $forkOwner = explode('/', $fork)[0];
        $forkName = explode('/', $fork)[1] ?? '';

        $compare = $this->cached('compare', "https://api.github.com/repos/{$repo}/compare/{$branch}...{$forkOwner}:{$forkName}:{$branch}?per_page=1", function (array $json): ?array {
            // base = upstream, head = our fork: ahead_by is ours-not-upstream,
            // behind_by is upstream-not-ours. per_page=1 trims the commit list;
            // the file list still makes this answer around a megabyte.
            if (! is_int($json['ahead_by'] ?? null) || ! is_int($json['behind_by'] ?? null)) {
                return null;
            }

            return ['ahead' => $json['ahead_by'], 'behind' => $json['behind_by']];
        });

        $release = fn (array $json): ?array => is_string($json['tag_name'] ?? null) && $json['tag_name'] !== ''
            ? ['tag' => $json['tag_name']]
            : null;

        return [
            'repo' => $repo,
            'fork' => $fork,
            'ahead' => $compare['ahead'] ?? null,
            'behind' => $compare['behind'] ?? null,
            'upstreamRelease' => $this->cached('release-upstream', "https://api.github.com/repos/{$repo}/releases/latest", $release)['tag'] ?? null,
            'forkRelease' => $this->cached('release-fork', "https://api.github.com/repos/{$fork}/releases/latest", $release)['tag'] ?? null,
            'openPulls' => $this->watchedCount($upstreamWatchState, 'pulls'),
            'openIssues' => $this->watchedCount($upstreamWatchState, 'issues'),
        ];
    }

    /**
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $extract
     * @return array<string, mixed>|null
     */
    private function cached(string $name, string $url, callable $extract): ?array
    {
        $key = 'lab.drift.upstream.'.$name.'.'.sha1($url);
        $hit = Cache::get($key);
        if (is_array($hit) && array_key_exists('value', $hit)) {
            return is_array($hit['value']) ? $hit['value'] : null;
        }

        $value = null;
        try {
            $response = Http::timeout((int) config('lab.upstream.timeout_seconds', 5))
                ->connectTimeout((int) config('lab.upstream.timeout_seconds', 5))
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->get($url);
            if ($response->successful() && is_array($response->json())) {
                $value = $extract($response->json());
            }
        } catch (Throwable) {
            $value = null;
        }

        $ttl = $value === null
            ? now()->addSeconds((int) config('lab.upstream.failure_cache_seconds', 60))
            : now()->addMinutes((int) config('lab.upstream.cache_minutes', 15));
        Cache::put($key, ['value' => $value], $ttl);

        return $value;
    }

    /**
     * Open upstream items as the watcher last saw them. The watcher reads only
     * the newest page, so a count that fills the page is a LOWER bound; and the
     * issues source filters pull requests out of a page shared with them, so
     * its count is always a lower bound.
     *
     * @param  array<string, mixed>|null  $state
     * @return array{count: int, lowerBound: bool}|null
     */
    private function watchedCount(?array $state, string $source): ?array
    {
        $entry = $state['sources'][$source] ?? null;
        if (! is_array($entry) || ($entry['ok'] ?? null) !== true || ! is_array($entry['data']['entries'] ?? null)) {
            return null;
        }

        $count = count($entry['data']['entries']);
        $page = (int) config('lab.upstream.watcher_page_size', 30);

        return ['count' => $count, 'lowerBound' => $source === 'issues' || $count >= $page];
    }
}
