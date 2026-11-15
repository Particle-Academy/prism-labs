<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Drift\ProviderWatchReader;
use App\Drift\UpstreamDrift;
use App\Drift\WatcherGit;
use App\Http\Controllers\Lab\DriftController;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DriftDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const COMPARE = 'https://api.github.com/repos/prism-php/prism/compare/main...Particle-Academy:prism:main?per_page=1';

    private const UPSTREAM_RELEASE = 'https://api.github.com/repos/prism-php/prism/releases/latest';

    private const FORK_RELEASE = 'https://api.github.com/repos/Particle-Academy/prism/releases/latest';

    private string $watch;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T15:00:00Z'));

        $this->watch = $this->copyFixture();
        config(['lab.provider_watch_path' => $this->watch]);
        $this->committedAt('2026-11-12T10:00:00Z');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            // git writes its objects read-only, which Windows refuses to delete.
            if (is_dir($dir.'/.git')) {
                foreach (File::allFiles($dir.'/.git', true) as $file) {
                    @chmod($file->getPathname(), 0666);
                }
            }
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function test_the_page_renders_through_the_real_route(): void
    {
        $this->fakeGitHub();
        // A fixture key: the session middleware needs one, and no real key is read.
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $this->app->detectEnvironment(fn () => 'local');
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();

        $this->get('/lab/drift')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Lab/Drift')
            ->where('overall', 'red')
            ->where('latest.changeCount', 3)
            ->where('upstream.behind', 34)
            ->where('upstream.ahead', 12));
    }

    public function test_providers_are_classified_green_amber_red_and_unknown(): void
    {
        $this->fakeGitHub();
        $tiles = collect($this->props()['providers'])->keyBy('name');

        // Every state file is a tile, including the ones with nothing to say.
        $this->assertSame(['anthropic', 'groq', 'mcp', 'mistral', 'openai', 'upstream'], $tiles->keys()->all());
        $this->assertSame('red', $tiles['openai']['status'], 'an openapi-spec change is a contract change');
        $this->assertSame('amber', $tiles['anthropic']['status'], 'a models-dev change is catalogue churn');
        $this->assertSame('green', $tiles['groq']['status']);
        $this->assertSame(0, $tiles['groq']['changeCount']);
        // The latest run's only MCP change is an official-version announcement,
        // which is catalogue — MCP is red because state still holds a gap.
        $this->assertSame('red', $tiles['mcp']['status']);
        $this->assertFalse($tiles['mcp']['changes'][0]['contract']);
        // A source whose last poll failed was never compared: not green.
        $this->assertSame('unknown', $tiles['mistral']['status']);
        $this->assertSame(1, $tiles['mistral']['failingSources']);
        $this->assertSame('green', $tiles['upstream']['status']);
    }

    public function test_an_mcp_gap_or_unreadable_server_in_a_change_is_a_contract_change(): void
    {
        $this->assertTrue(ProviderWatchReader::isContract(['source' => 'server-versions', 'subject' => 'prism-mcp-ts does not serve MCP 2026-07-28, one of the 5 newest official versions']));
        $this->assertTrue(ProviderWatchReader::isContract(['source' => 'server-versions', 'subject' => 'prism-mcp: HTTP 404, so its supported MCP versions cannot be checked']));
        $this->assertFalse(ProviderWatchReader::isContract(['source' => 'server-versions', 'subject' => 'MCP schema 2026-07-28 is one of the 5 newest official versions']));
        $this->assertTrue(ProviderWatchReader::isContract(['source' => 'openapi-spec', 'subject' => 'anything']));
        $this->assertFalse(ProviderWatchReader::isContract(['source' => 'models-dev', 'subject' => 'gap:looks-like-one']));
        $this->assertFalse(ProviderWatchReader::isContract(['source' => 'sdk-python', 'subject' => 'v1.2.3']));
    }

    public function test_mcp_without_a_standing_gap_and_only_catalogue_changes_is_amber(): void
    {
        $this->fakeGitHub();
        $this->rewriteJson('state/mcp.json', function (array $state): array {
            $state['sources']['server-versions']['data']['entries'] = array_values(array_filter(
                $state['sources']['server-versions']['data']['entries'],
                fn (array $e): bool => ! str_starts_with($e['id'], 'gap:'),
            ));

            return $state;
        });

        $mcp = collect($this->props()['providers'])->firstWhere('name', 'mcp');
        $this->assertSame('amber', $mcp['status']);
    }

    public function test_overall_is_green_only_when_every_provider_is_green_and_the_run_is_fresh(): void
    {
        $green = [['status' => 'green'], ['status' => 'green']];
        $fresh = ['readable' => true, 'compared' => true, 'ageHours' => 2];

        $this->assertSame('green', DriftController::overall($fresh, $green));
        $this->assertSame('amber', DriftController::overall($fresh, [...$green, ['status' => 'amber']]));
        $this->assertSame('red', DriftController::overall($fresh, [['status' => 'amber'], ['status' => 'red']]));
        $this->assertSame('amber', DriftController::overall($fresh, [...$green, ['status' => 'unknown']]));
        $this->assertSame('amber', DriftController::overall([...$fresh, 'ageHours' => 49], $green), 'a stale run is not a green run');
        $this->assertSame('unknown', DriftController::overall([...$fresh, 'compared' => false], $green));
        $this->assertSame('unknown', DriftController::overall([...$fresh, 'readable' => false], $green));
    }

    public function test_the_archive_trend_is_ordered_split_and_counts_unreadable_runs(): void
    {
        $this->fakeGitHub();
        $trend = $this->props()['trend'];

        $this->assertSame(1, $trend['unreadable']);
        $this->assertSame(
            ['2026-11-13T13:00:00Z', '2026-11-14T13:00:00Z', '2026-11-15T13:00:00Z'],
            array_column($trend['runs'], 'generatedAt'),
        );
        $this->assertSame([2, 3, 3], array_column($trend['runs'], 'changeCount'));
        $this->assertSame([0, 2, 1], array_column($trend['runs'], 'contractCount'));
        $this->assertSame([2, 1, 2], array_column($trend['runs'], 'catalogueCount'));
    }

    public function test_the_trend_keeps_only_the_last_thirty_runs(): void
    {
        $this->fakeGitHub();
        $start = CarbonImmutable::parse('2026-09-01T13:00:00Z');
        for ($i = 0; $i < 35; $i++) {
            $at = $start->addDays($i);
            File::put($this->watch.'/reports/archive/'.$at->format('Y-m-d\THis\Z').'.json', json_encode([
                'generated_at' => $at->format('Y-m-d\TH:i:s\Z'), 'change_count' => $i, 'changes' => [],
            ]));
        }

        $trend = $this->props()['trend'];
        $runs = $trend['runs'];
        // The window is the last thirty FILES; the malformed one is inside it.
        $this->assertSame(1, $trend['unreadable']);
        $this->assertCount(ProviderWatchReader::ARCHIVE_RUNS - 1, $runs);
        $this->assertSame('2026-09-10T13:00:00Z', $runs[0]['generatedAt']);
        $this->assertSame('2026-11-15T13:00:00Z', end($runs)['generatedAt']);
    }

    public function test_mcp_chips_are_red_for_a_gap_and_green_otherwise(): void
    {
        $this->fakeGitHub();
        $mcp = $this->props()['mcp'];

        $this->assertTrue($mcp['readable']);
        $this->assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'], array_column($mcp['versions'], 'version'));
        $this->assertSame(['red', 'green', 'green', 'green', 'green'], array_column($mcp['versions'], 'status'));
        $this->assertSame(['prism-mcp-ts'], $mcp['versions'][0]['gapServers']);
    }

    public function test_an_unreadable_mcp_server_makes_ungapped_versions_unconfirmed(): void
    {
        $this->fakeGitHub();
        $this->rewriteJson('state/mcp.json', function (array $state): array {
            $state['sources']['server-versions']['data']['entries'][] = ['id' => 'unreadable:prism-mcp-py', 'title' => 'prism-mcp-py: HTTP 404'];

            return $state;
        });

        $mcp = $this->props()['mcp'];
        $this->assertSame(['red', 'amber', 'amber', 'amber', 'amber'], array_column($mcp['versions'], 'status'));
        $this->assertSame(['prism-mcp-py'], $mcp['unreadableServers']);
    }

    public function test_baseline_freshness_is_coloured_by_days_since_the_state_commit(): void
    {
        $this->fakeGitHub();
        $baseline = $this->props()['baseline'];
        $this->assertSame(3, $baseline['days']);
        $this->assertSame('amber', $baseline['status']);

        $this->assertSame('green', DriftController::baselineStatus(0));
        $this->assertSame('green', DriftController::baselineStatus(2));
        $this->assertSame('amber', DriftController::baselineStatus(7));
        $this->assertSame('red', DriftController::baselineStatus(8));
        $this->assertSame('unknown', DriftController::baselineStatus(null));
    }

    public function test_the_real_git_reader_reads_the_state_commit_date_of_its_own_repository(): void
    {
        $repo = $this->copyFixture();
        $env = ['GIT_AUTHOR_DATE' => '2026-11-10T08:00:00+00:00', 'GIT_COMMITTER_DATE' => '2026-11-10T08:00:00+00:00'];
        foreach ([['init', '-q'], ['add', 'state'], ['-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'state']] as $args) {
            $process = new Process(['git', ...$args], $repo, $env);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $date = (new WatcherGit)->lastStateCommit($repo);
        $this->assertNotNull($date);
        $this->assertSame('2026-11-10T08:00:00+00:00', $date->toIso8601String());

        // A directory that is not its own repository must not borrow the date
        // of whatever repository it happens to sit inside.
        $nested = $repo.'/state';
        $this->assertNull((new WatcherGit)->lastStateCommit($nested));
        $this->assertNull((new WatcherGit)->lastStateCommit($repo.'/does-not-exist'));
    }

    public function test_upstream_numbers_come_from_github_and_the_watcher_without_a_token(): void
    {
        $this->fakeGitHub();
        $upstream = $this->props()['upstream'];

        $this->assertSame(12, $upstream['ahead']);
        $this->assertSame(34, $upstream['behind']);
        $this->assertSame('v0.99.0', $upstream['upstreamRelease']);
        $this->assertSame('v0.123.0', $upstream['forkRelease']);
        // 30 is the watcher's page size, so 30 PRs is "at least 30".
        $this->assertSame(['count' => 30, 'lowerBound' => true], $upstream['openPulls']);
        $this->assertSame(['count' => 3, 'lowerBound' => true], $upstream['openIssues']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => ! $r->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $r): bool => $r->hasHeader('Authorization'));
    }

    public function test_github_answers_are_cached_for_fifteen_minutes(): void
    {
        $this->fakeGitHub();
        $this->props();
        $this->props();
        Http::assertSentCount(3);

        $this->travel(16)->minutes();
        $this->props();
        Http::assertSentCount(6);
    }

    public function test_a_failed_or_rate_limited_github_call_is_unknown_never_zero(): void
    {
        Http::fake([
            self::COMPARE => Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']),
            self::UPSTREAM_RELEASE => fn () => throw new ConnectionException('unreachable'),
            self::FORK_RELEASE => Http::response(['message' => 'Not Found'], 404),
        ]);

        $upstream = $this->props()['upstream'];
        $this->assertNull($upstream['ahead']);
        $this->assertNull($upstream['behind']);
        $this->assertNull($upstream['upstreamRelease']);
        $this->assertNull($upstream['forkRelease']);
        // Watcher-sourced counts are independent of GitHub being down.
        $this->assertSame(30, $upstream['openPulls']['count']);

        // A failure is retried soon, not held for fifteen minutes. (A refused
        // connection is never recorded as sent, so two of three calls count.)
        Http::assertSentCount(2);
        $this->props();
        Http::assertSentCount(2);
        $this->travel(61)->seconds();
        $this->props();
        Http::assertSentCount(4);
    }

    public function test_a_compare_payload_without_integers_is_unknown(): void
    {
        Http::fake([
            self::COMPARE => Http::response(['ahead_by' => '12', 'status' => 'diverged']),
            self::UPSTREAM_RELEASE => Http::response(['tag_name' => '']),
            self::FORK_RELEASE => Http::response(['tag_name' => 'v0.123.0']),
        ]);

        $upstream = $this->props()['upstream'];
        $this->assertNull($upstream['ahead']);
        $this->assertNull($upstream['behind']);
        $this->assertNull($upstream['upstreamRelease']);
        $this->assertSame('v0.123.0', $upstream['forkRelease']);
    }

    public function test_a_missing_watcher_is_unknown_everywhere_and_never_zero(): void
    {
        $this->fakeGitHub();
        config(['lab.provider_watch_path' => $this->watch.'/nowhere']);
        $this->committedAt(null);

        $props = $this->props();
        $this->assertSame('unknown', $props['overall']);
        $this->assertFalse($props['latest']['readable']);
        $this->assertNull($props['latest']['changeCount']);
        $this->assertNull($props['latest']['contractCount']);
        $this->assertSame([], $props['providers']);
        $this->assertSame([], $props['trend']['runs']);
        $this->assertFalse($props['mcp']['readable']);
        $this->assertNull($props['baseline']['days']);
        $this->assertSame('unknown', $props['baseline']['status']);
        $this->assertNull($props['upstream']['openPulls']);
        $this->assertNull($props['upstream']['openIssues']);
    }

    public function test_a_missing_latest_report_makes_every_tile_unknown_not_green(): void
    {
        $this->fakeGitHub();
        File::delete($this->watch.'/reports/latest.json');

        $props = $this->props();
        $this->assertSame('unknown', $props['overall']);
        $this->assertNull($props['latest']['changeCount']);
        $statuses = collect($props['providers'])->pluck('status', 'name');
        // MCP's standing gap is still known from state; everything else is not.
        $this->assertSame('red', $statuses['mcp']);
        $this->assertSame(['unknown'], $statuses->except('mcp')->unique()->values()->all());
        $this->assertNull(collect($props['providers'])->firstWhere('name', 'groq')['changeCount']);
    }

    public function test_an_unreadable_state_file_is_still_a_tile_and_unknown(): void
    {
        $this->fakeGitHub();
        File::put($this->watch.'/state/groq.json', '{ broken');
        File::delete($this->watch.'/state/upstream.json');

        $props = $this->props();
        $groq = collect($props['providers'])->firstWhere('name', 'groq');
        $this->assertSame('unknown', $groq['status']);
        $this->assertFalse($groq['stateReadable']);
        $this->assertNull($props['upstream']['openPulls']);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return DriftController::props(app(ProviderWatchReader::class), app(UpstreamDrift::class));
    }

    private function fakeGitHub(): void
    {
        Http::fake([
            self::COMPARE => Http::response(['status' => 'diverged', 'ahead_by' => 12, 'behind_by' => 34]),
            self::UPSTREAM_RELEASE => Http::response(['tag_name' => 'v0.99.0']),
            self::FORK_RELEASE => Http::response(['tag_name' => 'v0.123.0']),
        ]);
    }

    private function committedAt(?string $date): void
    {
        $this->app->instance(WatcherGit::class, new class($date) extends WatcherGit
        {
            public function __construct(private readonly ?string $date) {}

            public function lastStateCommit(string $path): ?CarbonImmutable
            {
                return $this->date === null ? null : CarbonImmutable::parse($this->date);
            }
        });
    }

    private function copyFixture(): string
    {
        $dir = storage_path('framework/testing/drift-'.bin2hex(random_bytes(6)));
        File::copyDirectory(base_path('tests/fixtures/provider-watch'), $dir);
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function rewriteJson(string $relative, callable $change): void
    {
        $file = $this->watch.'/'.$relative;
        File::put($file, json_encode($change(json_decode(File::get($file), true))));
    }
}
