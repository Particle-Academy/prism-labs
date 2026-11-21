<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Drift\ProviderWatchReader;
use App\Drift\UpstreamDrift;
use App\Drift\UpstreamPullRequests;
use App\Drift\WatcherGit;
use App\Http\Controllers\Lab\DriftController;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Our PRs to upstream prism-php/prism, over public, unauthenticated search. */
final class UpstreamPullRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = 'https://api.github.com/search/issues?q=repo:prism-php/prism+is:pr+author:wishborn&per_page=100';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T15:00:00Z'));
        config(['lab.upstream.repo' => 'prism-php/prism', 'lab.upstream.pr_authors' => ['wishborn'], 'lab.upstream.pr_stale_days' => 30]);
    }

    public function test_prs_are_classified_open_merged_and_closed_unmerged(): void
    {
        Http::fake([self::SEARCH => Http::response($this->page([
            $this->pr(1047, 'closed', '2026-11-01T10:00:00Z', '2026-11-07T09:00:00Z', '2026-11-07T09:00:00Z'),
            $this->pr(1046, 'closed', '2026-11-01T09:00:00Z', '2026-11-07T08:00:00Z', '2026-11-07T08:00:00Z'),
            $this->pr(1045, 'open', '2026-11-10T00:00:00Z', '2026-11-12T00:00:00Z'),
            $this->pr(1028, 'open', '2026-10-01T00:00:00Z', '2026-10-20T00:00:00Z'),
            // Closed WITHOUT merged_at: rejected, not merged.
            $this->pr(1010, 'closed', '2026-09-01T00:00:00Z', '2026-09-05T00:00:00Z'),
            // Search also returns issues; one without `pull_request` is not a PR.
            ['number' => 999, 'title' => 'an issue', 'state' => 'open', 'created_at' => '2026-11-01T00:00:00Z',
                'updated_at' => '2026-11-01T00:00:00Z', 'html_url' => 'https://github.com/prism-php/prism/issues/999'],
        ]))]);

        $result = app(UpstreamPullRequests::class)->read(CarbonImmutable::now());
        $pulls = collect($result['pulls'])->keyBy('number');

        $this->assertTrue($result['readable']);
        $this->assertSame(['open' => 2, 'merged' => 2, 'closed' => 1], $result['counts']);
        $this->assertSame(66.7, $result['mergeRate'], '2 of the 3 decided PRs merged');
        $this->assertSame([1047, 1046, 1045, 1028, 1010], $pulls->keys()->all(), 'newest first, the issue dropped');
        $this->assertSame('merged', $pulls[1047]['state']);
        $this->assertSame('closed', $pulls[1010]['state']);
        $this->assertSame('open', $pulls[1045]['state']);
        $this->assertSame(['ageDays' => 45, 'idleDays' => 26, 'stale' => true],
            array_intersect_key($pulls[1028], array_flip(['ageDays', 'idleDays', 'stale'])), 'open past 30 days is stale');
        $this->assertFalse($pulls[1045]['stale']);
        $this->assertFalse($pulls[1010]['stale'], 'only an OPEN PR can be stale');
        $this->assertSame('https://github.com/prism-php/prism/pull/1047', $pulls[1047]['url']);
        $this->assertCount(UpstreamPullRequests::SPARK_WEEKS, $result['mergedPerWeek']);
        $this->assertSame([0, 2], [$result['mergedPerWeek'][9], $result['mergedPerWeek'][11]], 'cumulative merges by week');
    }

    public function test_search_is_unauthenticated_and_cached(): void
    {
        config(['services.github.token' => 'ghp_FIXTURE_never_sent']);
        Http::fake([self::SEARCH => Http::response($this->page([$this->pr(1, 'open', '2026-11-10T00:00:00Z', '2026-11-10T00:00:00Z')]))]);

        app(UpstreamPullRequests::class)->read(CarbonImmutable::now());
        app(UpstreamPullRequests::class)->read(CarbonImmutable::now());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->url() === self::SEARCH
            && $r->header('Authorization') === [] && ! str_contains(json_encode($r->headers()), 'ghp_'));
    }

    public function test_a_failed_search_is_unknown_never_zero(): void
    {
        Http::fake([self::SEARCH => Http::response(['message' => 'API rate limit exceeded'], 403)]);

        $result = app(UpstreamPullRequests::class)->read(CarbonImmutable::now());

        $this->assertFalse($result['readable']);
        $this->assertNull($result['counts']);
        $this->assertNull($result['mergeRate']);
        $this->assertSame([], $result['pulls']);
    }

    public function test_one_failing_author_makes_the_whole_panel_unknown(): void
    {
        config(['lab.upstream.pr_authors' => ['wishborn', 'someone-else']]);
        Http::fake([
            self::SEARCH => Http::response($this->page([$this->pr(1, 'open', '2026-11-10T00:00:00Z', '2026-11-10T00:00:00Z')])),
            'https://api.github.com/search/issues?q=repo:prism-php/prism+is:pr+author:someone-else*' => Http::response([], 500),
        ]);

        $result = app(UpstreamPullRequests::class)->read(CarbonImmutable::now());

        $this->assertFalse($result['readable'], 'counting only the readable author would undercount');
        $this->assertNull($result['counts']);
    }

    public function test_a_malformed_login_is_never_spliced_into_the_query(): void
    {
        config(['lab.upstream.pr_authors' => ['wishborn+repo:evil/repo', 'x author:other', '-bad']]);
        Http::fake();

        $result = app(UpstreamPullRequests::class)->read(CarbonImmutable::now());

        Http::assertNothingSent();
        $this->assertFalse($result['readable']);
        $this->assertSame([], $result['authors']);
    }

    public function test_a_link_outside_the_upstream_repo_is_dropped(): void
    {
        $pr = $this->pr(5, 'open', '2026-11-10T00:00:00Z', '2026-11-10T00:00:00Z');
        $pr['html_url'] = 'https://evil.example/prism-php/prism/pull/5';
        Http::fake([self::SEARCH => Http::response($this->page([$pr]))]);

        $result = app(UpstreamPullRequests::class)->read(CarbonImmutable::now());

        $this->assertNull($result['pulls'][0]['url']);
    }

    public function test_more_results_than_one_page_are_a_lower_bound(): void
    {
        Http::fake([self::SEARCH => Http::response([...$this->page([$this->pr(1, 'open', '2026-11-10T00:00:00Z', '2026-11-10T00:00:00Z')]), 'total_count' => 150])]);

        $this->assertTrue(app(UpstreamPullRequests::class)->read(CarbonImmutable::now())['lowerBound']);
    }

    public function test_the_drift_page_props_carry_the_panel(): void
    {
        Http::fake([
            self::SEARCH => Http::response($this->page([$this->pr(1047, 'closed', '2026-11-01T00:00:00Z', '2026-11-07T00:00:00Z', '2026-11-07T00:00:00Z')])),
            'https://api.github.com/*' => Http::response([], 503),
        ]);
        $dir = storage_path('framework/testing/prs-'.bin2hex(random_bytes(6)));
        File::copyDirectory(base_path('tests/fixtures/provider-watch'), $dir);
        config(['lab.provider_watch_path' => $dir]);
        $this->app->instance(WatcherGit::class, new class extends WatcherGit
        {
            public function __construct() {}

            public function lastStateCommit(string $path): ?CarbonImmutable
            {
                return null;
            }
        });

        try {
            $props = DriftController::props(app(ProviderWatchReader::class), app(UpstreamDrift::class));
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(['open' => 0, 'merged' => 1, 'closed' => 0], $props['upstreamPulls']['counts']);
        $this->assertSame(1047, $props['upstreamPulls']['pulls'][0]['number']);
    }

    /** @param list<array<string, mixed>> $items */
    private function page(array $items): array
    {
        return ['total_count' => count($items), 'incomplete_results' => false, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function pr(int $number, string $state, string $created, string $updated, ?string $mergedAt = null): array
    {
        return [
            'number' => $number, 'title' => "PR {$number}", 'state' => $state,
            'created_at' => $created, 'updated_at' => $updated, 'closed_at' => $state === 'closed' ? $updated : null,
            'html_url' => "https://github.com/prism-php/prism/pull/{$number}",
            'pull_request' => ['url' => "https://api.github.com/repos/prism-php/prism/pulls/{$number}", 'merged_at' => $mergedAt],
        ];
    }
}
