<?php

declare(strict_types=1);

namespace App\Drift;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the prism-provider-watch checkout into what the drift dashboard draws.
 *
 * Every figure is either a value read from the watcher or null, and null is
 * rendered as "unknown". Nothing here defaults to zero: a missing report that
 * read as "0 changes" would be the dashboard saying there is no drift when it
 * could not look.
 *
 * Classification of one change:
 *  - CONTRACT (red): an `openapi-spec` change (the watcher's own
 *    DriftReport::CONTRACT_SOURCES), or an MCP `server-versions` change that is
 *    not the announcement of an official schema version. The watcher titles
 *    those "MCP schema <v> is one of ..."; everything else that source emits is
 *    a server gap or an unreadable server, i.e. a breach of the five-version
 *    rule.
 *  - CATALOGUE (amber): everything else — models-dev roster moves, github-atom
 *    releases/commits, upstream issue and PR feeds, official MCP versions.
 *
 * A provider is red if any of its latest changes is a contract change, amber if
 * it has only catalogue changes, green if it has none — unless its state file
 * is unreadable or one of its sources' last poll failed, which is unknown
 * rather than green. MCP is also red while
 * its committed state still carries a gap or an unreadable server, whether or
 * not that was new in the latest run: the breach persists until it is fixed.
 */
class ProviderWatchReader
{
    public const ARCHIVE_RUNS = 30;

    private const OFFICIAL_ANNOUNCEMENT = '/^MCP schema \S+ is one of\b/';

    public function __construct(private readonly WatcherGit $git) {}

    /** @return array<string, mixed> */
    public function read(string $path, CarbonImmutable $now): array
    {
        $latest = $this->json($path.'/reports/latest.json');
        $states = $this->states($path);

        $changes = $this->changes($latest);

        return [
            'latest' => $this->latestSummary($latest, $changes, $now),
            'providers' => $this->providers($states, $changes),
            'trend' => $this->trend($path),
            'mcp' => $this->mcp($states['mcp'] ?? null),
            'baseline' => $this->baseline($path, $now),
            'upstreamWatch' => $states['upstream'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    public static function isContract(array $change): bool
    {
        $source = $change['source'] ?? null;
        if ($source === 'openapi-spec') {
            return true;
        }

        return $source === 'server-versions'
            && preg_match(self::OFFICIAL_ANNOUNCEMENT, (string) ($change['subject'] ?? '')) !== 1;
    }

    /**
     * @param  array<string, mixed>|null  $latest
     * @return list<array<string, string>>|null
     */
    private function changes(?array $latest): ?array
    {
        if ($latest === null || ! is_array($latest['changes'] ?? null)) {
            return null;
        }

        $changes = [];
        foreach ($latest['changes'] as $change) {
            if (! is_array($change) || ! is_string($change['provider'] ?? null)) {
                continue;
            }
            $changes[] = [
                'provider' => $change['provider'],
                'source' => (string) ($change['source'] ?? ''),
                'type' => (string) ($change['type'] ?? ''),
                'subject' => (string) ($change['subject'] ?? ''),
                'detail' => (string) ($change['detail'] ?? ''),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>|null  $latest
     * @param  list<array<string, string>>|null  $changes
     * @return array<string, mixed>
     */
    private function latestSummary(?array $latest, ?array $changes, CarbonImmutable $now): array
    {
        $generatedAt = null;
        if (is_string($latest['generated_at'] ?? null)) {
            try {
                $generatedAt = CarbonImmutable::parse($latest['generated_at']);
            } catch (Throwable) {
                $generatedAt = null;
            }
        }

        $count = $latest !== null && is_int($latest['change_count'] ?? null)
            ? $latest['change_count']
            : ($changes === null ? null : count($changes));

        return [
            'readable' => $latest !== null && $changes !== null,
            'generatedAt' => $generatedAt?->toIso8601String(),
            'ageHours' => $generatedAt === null ? null : (int) floor($generatedAt->diffInHours($now, true)),
            // `compared: false` means the run could not say whether anything changed.
            'compared' => is_bool($latest['compared'] ?? null) ? $latest['compared'] : null,
            'uncomparedSources' => is_int($latest['uncompared_sources'] ?? null) ? $latest['uncompared_sources'] : null,
            'changeCount' => $count,
            'contractCount' => $changes === null ? null : count(array_filter($changes, self::isContract(...))),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $states
     * @param  list<array<string, string>>|null  $changes
     * @return list<array<string, mixed>>
     */
    private function providers(array $states, ?array $changes): array
    {
        $names = array_keys($states);
        foreach ($changes ?? [] as $change) {
            // A provider the report names but whose state file is missing is
            // still drawn: hiding it would hide its drift.
            $names[] = $change['provider'];
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        $tiles = [];
        foreach ($names as $name) {
            $state = $states[$name] ?? null;
            $own = $changes === null ? null : array_values(array_filter($changes, fn (array $c): bool => $c['provider'] === $name));

            $failing = null;
            if ($state !== null) {
                $failing = 0;
                foreach ($state['sources'] as $source) {
                    if (! is_array($source) || ($source['ok'] ?? null) !== true) {
                        $failing++;
                    }
                }
            }

            $standingBreach = $name === 'mcp' && $state !== null && $this->mcpBreaches($state) !== [];

            $status = match (true) {
                $standingBreach => 'red',
                $own === null => 'unknown',
                array_filter($own, self::isContract(...)) !== [] => 'red',
                $own !== [] => 'amber',
                // No change from a source whose last poll failed is not "no
                // change": it was never compared.
                $state === null, $failing > 0 => 'unknown',
                default => 'green',
            };

            $tiles[] = [
                'name' => $name,
                'status' => $status,
                'changeCount' => $own === null ? null : count($own),
                'failingSources' => $failing,
                'stateReadable' => $state !== null,
                'changes' => array_map(fn (array $c): array => [
                    'source' => $c['source'],
                    'type' => $c['type'],
                    'subject' => $c['subject'],
                    // e.g. "input 3.00→2.50 /Mtok" on a models-dev price change.
                    'detail' => $c['detail'],
                    'contract' => self::isContract($c),
                ], $own ?? []),
            ];
        }

        return $tiles;
    }

    /** @return array<string, mixed> */
    private function trend(string $path): array
    {
        $files = glob($path.'/reports/archive/*.json');
        if ($files === false || $files === []) {
            return ['runs' => [], 'unreadable' => 0, 'readable' => is_dir($path.'/reports/archive')];
        }

        sort($files, SORT_STRING);
        $files = array_slice($files, -self::ARCHIVE_RUNS);

        $runs = [];
        $unreadable = 0;
        foreach ($files as $file) {
            $report = $this->json($file);
            $changes = $this->changes($report);
            if ($report === null || $changes === null || ! is_string($report['generated_at'] ?? null)) {
                $unreadable++;

                continue;
            }
            $total = is_int($report['change_count'] ?? null) ? $report['change_count'] : count($changes);
            $contract = count(array_filter($changes, self::isContract(...)));
            $runs[] = [
                'generatedAt' => $report['generated_at'],
                'changeCount' => $total,
                'contractCount' => $contract,
                'catalogueCount' => max(0, $total - $contract),
            ];
        }

        return ['runs' => $runs, 'unreadable' => $unreadable, 'readable' => true];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    private function mcp(?array $state): array
    {
        $entries = $this->serverVersionEntries($state);
        if ($entries === null) {
            return ['readable' => false, 'versions' => [], 'unreadableServers' => []];
        }

        $versions = [];
        $gaps = [];
        $unreadable = [];
        foreach ($entries as $id) {
            if (str_starts_with($id, 'official:')) {
                $versions[] = substr($id, strlen('official:'));
            } elseif (str_starts_with($id, 'gap:')) {
                // gap:<server>:<version>; the version is the last segment.
                $parts = explode(':', $id);
                $version = (string) array_pop($parts);
                $gaps[$version][] = implode(':', array_slice($parts, 1));
            } elseif (str_starts_with($id, 'unreadable:')) {
                $unreadable[] = substr($id, strlen('unreadable:'));
            }
        }

        return [
            'readable' => true,
            'versions' => array_map(fn (string $v): array => [
                'version' => $v,
                // A server that could not be read might lack any version, so a
                // version with no recorded gap is only confirmed when every
                // server was read.
                'status' => isset($gaps[$v]) ? 'red' : ($unreadable === [] ? 'green' : 'amber'),
                'gapServers' => $gaps[$v] ?? [],
            ], $versions),
            'unreadableServers' => $unreadable,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function mcpBreaches(array $state): array
    {
        return array_values(array_filter(
            $this->serverVersionEntries($state) ?? [],
            fn (string $id): bool => str_starts_with($id, 'gap:') || str_starts_with($id, 'unreadable:'),
        ));
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return list<string>|null
     */
    private function serverVersionEntries(?array $state): ?array
    {
        $source = $state['sources']['server-versions'] ?? null;
        if (! is_array($source) || ($source['ok'] ?? null) !== true || ! is_array($source['data']['entries'] ?? null)) {
            return null;
        }

        $ids = [];
        foreach ($source['data']['entries'] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function baseline(string $path, CarbonImmutable $now): array
    {
        $committed = $this->git->lastStateCommit($path);

        return [
            'committedAt' => $committed?->toIso8601String(),
            'days' => $committed === null ? null : (int) floor($committed->diffInDays($now, true)),
        ];
    }

    /**
     * The watcher's latest models-dev price snapshot per provider (G-291),
     * USD per million tokens: input, output, cache_read, cache_write, each a
     * float or null ("not published", never free).
     *
     * A provider maps to null when its models-dev source is unreadable, failed
     * its last poll, or predates price recording: none of those is "no prices".
     *
     * @return array<string, array<string, array<string, float|null>>|null>
     */
    public function catalogPrices(string $path): array
    {
        $prices = [];
        foreach ($this->states($path) as $provider => $state) {
            $source = $state['sources']['models-dev'] ?? null;
            if (! is_array($source)) {
                continue;
            }
            $snapshot = $source['data']['prices'] ?? null;
            if (($source['ok'] ?? null) !== true || ! is_array($snapshot)) {
                $prices[$provider] = null;

                continue;
            }
            $models = [];
            foreach ($snapshot as $model => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $fields = [];
                foreach (['input', 'output', 'cache_read', 'cache_write'] as $field) {
                    $value = $row[$field] ?? null;
                    $fields[$field] = is_int($value) || is_float($value) ? (float) $value : null;
                }
                $models[(string) $model] = $fields;
            }
            $prices[$provider] = $models;
        }
        ksort($prices, SORT_STRING);

        return $prices;
    }

    /** @return array<string, array<string, mixed>|null> */
    private function states(string $path): array
    {
        $files = glob($path.'/state/*.json');
        if ($files === false) {
            return [];
        }

        $states = [];
        foreach ($files as $file) {
            $state = $this->json($file);
            $states[basename($file, '.json')] = $state !== null && is_array($state['sources'] ?? null) ? $state : null;
        }

        return $states;
    }

    /** @return array<string, mixed>|null */
    private function json(string $file): ?array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
