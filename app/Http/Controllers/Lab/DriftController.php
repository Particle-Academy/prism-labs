<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lab;

use App\Billing\BillingPanel;
use App\Billing\PriceMismatches;
use App\Drift\ProviderWatchReader;
use App\Drift\UpstreamDrift;
use App\Drift\UpstreamPullRequests;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drift dashboard: provider drift from prism-provider-watch, and our fork's
 * drift from upstream prism-php/prism. Read-only; nothing here spends money or
 * writes anywhere but the cache.
 */
final class DriftController extends Controller
{
    /** A latest report older than this means the watcher has stopped running. */
    public const STALE_RUN_HOURS = 48;

    public function show(ProviderWatchReader $watch, UpstreamDrift $upstream): Response
    {
        return Inertia::render('Lab/Drift', self::props($watch, $upstream));
    }

    /** @return array<string, mixed> */
    public static function props(ProviderWatchReader $watch, UpstreamDrift $upstream): array
    {
        $data = $watch->read((string) config('lab.provider_watch_path'), CarbonImmutable::now());

        $upstreamWatch = $data['upstreamWatch'];
        unset($data['upstreamWatch']);

        $data['overall'] = self::overall($data['latest'], $data['providers']);
        $data['baseline']['status'] = self::baselineStatus($data['baseline']['days']);
        $data['upstream'] = $upstream->read(is_array($upstreamWatch) ? $upstreamWatch : null);

        // G-291: recorded vs billed, and configured vs catalogue prices. Not
        // folded into `overall`, which is the watcher's drift verdict.
        $data['billing'] = app(BillingPanel::class)->read(CarbonImmutable::now());
        $data['prices'] = app(PriceMismatches::class)->read((string) config('lab.provider_watch_path'));
        $data['upstreamPulls'] = app(UpstreamPullRequests::class)->read(CarbonImmutable::now());

        return $data;
    }

    /**
     * unknown: the latest report could not be read, or it compared nothing.
     * red: any provider has a contract change (or MCP a standing gap).
     * amber: any catalogue change, any provider whose state is unreadable, or a
     *        latest report older than STALE_RUN_HOURS.
     * green: otherwise.
     *
     * @param  array<string, mixed>  $latest
     * @param  list<array<string, mixed>>  $providers
     */
    public static function overall(array $latest, array $providers): string
    {
        if ($latest['readable'] !== true || $latest['compared'] === false) {
            return 'unknown';
        }

        $statuses = array_column($providers, 'status');
        if (in_array('red', $statuses, true)) {
            return 'red';
        }

        if (in_array('amber', $statuses, true) || in_array('unknown', $statuses, true)
            || $latest['ageHours'] === null || $latest['ageHours'] > self::STALE_RUN_HOURS) {
            return 'amber';
        }

        return 'green';
    }

    public static function baselineStatus(?int $days): string
    {
        return match (true) {
            $days === null => 'unknown',
            $days <= 2 => 'green',
            $days <= 7 => 'amber',
            default => 'red',
        };
    }
}
