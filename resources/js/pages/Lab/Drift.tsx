import { Badge, Button, Card, Chart, Grid, Heading, Stat, Text, Tooltip } from '@particle-academy/react-fancy';
import { type ReactNode, useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import { labBlurb } from '../../components/lab-nav';

type Status = 'green' | 'amber' | 'red' | 'unknown';

type ProviderChange = { source: string; type: string; subject: string; detail: string; contract: boolean };

type ProviderTile = {
    name: string;
    status: Status;
    changeCount: number | null;
    failingSources: number | null;
    stateReadable: boolean;
    changes: ProviderChange[];
};

type Run = { generatedAt: string; changeCount: number; contractCount: number; catalogueCount: number };

type WatchedCount = { count: number; lowerBound: boolean } | null;

type Band = 'green' | 'amber' | 'red' | 'unknown' | 'not_configured';

type BillingCell = { status: string; band: Band; reason: string | null; recorded: number | null; billed: number | null; deltaPercent: number | null };

type BillingProvider = {
    provider: string;
    status: Band;
    reason: string | null;
    days: { day: string; band: Band; cost: BillingCell; tokens: BillingCell }[];
};

type PriceTile = {
    provider: string;
    model: string;
    source: 'harness' | 'lab-list';
    fields: { field: string; configured: number | null; catalogue: number }[];
    reason: string | null;
};

type UpstreamPull = {
    number: number;
    title: string;
    state: 'open' | 'merged' | 'closed';
    createdAt: string;
    updatedAt: string;
    mergedAt: string | null;
    url: string | null;
    ageDays: number;
    idleDays: number;
    stale: boolean;
};

type Props = {
    overall: Status;
    latest: {
        readable: boolean;
        generatedAt: string | null;
        ageHours: number | null;
        compared: boolean | null;
        uncomparedSources: number | null;
        changeCount: number | null;
        contractCount: number | null;
    };
    providers: ProviderTile[];
    trend: { runs: Run[]; unreadable: number; readable: boolean };
    mcp: {
        readable: boolean;
        versions: { version: string; status: Status; gapServers: string[] }[];
        unreadableServers: string[];
    };
    baseline: { committedAt: string | null; days: number | null; status: Status };
    upstream: {
        repo: string;
        fork: string;
        ahead: number | null;
        behind: number | null;
        upstreamRelease: string | null;
        forkRelease: string | null;
        openPulls: WatchedCount;
        openIssues: WatchedCount;
    };
    billing: {
        thresholdPercent: number;
        floorTokens: number;
        floorUsd: number;
        graceHours: number;
        providers: BillingProvider[];
    };
    upstreamPulls: {
        readable: boolean;
        repo: string;
        authors: string[];
        counts: { open: number; merged: number; closed: number } | null;
        mergeRate: number | null;
        mergedPerWeek: number[];
        lowerBound: boolean;
        pulls: UpstreamPull[];
    };
    prices: {
        status: 'ok' | 'mismatch' | 'unknown';
        harnessConfiguredModels: number;
        catalogueProviders: string[];
        unreadableProviders: string[];
        tiles: PriceTile[];
    };
};

// Every figure that could not be read says so. Zero is a reading, and a zero
// standing in for "could not look" would say there is no drift.
const UNKNOWN = 'unknown';

const TONE: Record<Status, 'green' | 'amber' | 'red' | 'zinc'> = {
    green: 'green',
    amber: 'amber',
    red: 'red',
    unknown: 'zinc',
};

const LABEL: Record<Status, string> = {
    green: 'clear',
    amber: 'catalogue',
    red: 'contract',
    unknown: UNKNOWN,
};

function n(value: number | null | undefined): string {
    return value === null || value === undefined ? UNKNOWN : value.toLocaleString();
}

function watched(value: WatchedCount): string {
    if (value === null) return UNKNOWN;
    return value.lowerBound ? `${value.count}+` : String(value.count);
}

function StatusBadge({ status, children, size = 'md' }: { status: Status; children?: ReactNode; size?: 'sm' | 'md' | 'lg' }) {
    return <Badge color={TONE[status]} variant="soft" size={size} dot>{children ?? LABEL[status]}</Badge>;
}

function ProviderCard({ tile }: { tile: ProviderTile }) {
    const [open, setOpen] = useState(false);
    const expandable = tile.changes.length > 0;

    return (
        <Card variant="outlined" padding="sm" data-testid={`drift-provider-${tile.name}`} data-status={tile.status}>
            <Card.Body className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <Text weight="semibold" className="font-mono">{tile.name}</Text>
                    <StatusBadge status={tile.status} size="sm" />
                </div>
                <div className="flex items-end justify-between gap-2">
                    <Stat value={n(tile.changeCount)} label="changes" size="sm" />
                    {expandable && (
                        <Button variant="ghost" size="sm" aria-expanded={open} onClick={() => setOpen(v => !v)}>
                            {open ? 'Hide' : 'Show'}
                        </Button>
                    )}
                </div>
                {(tile.failingSources ?? 0) > 0 && <Badge color="orange" variant="outline" size="sm">{tile.failingSources} source failing</Badge>}
                {!tile.stateReadable && <Badge color="zinc" variant="outline" size="sm">state unreadable</Badge>}
                {open && (
                    <div className="space-y-1 border-t border-[var(--k-hairline)] pt-2">
                        {tile.changes.map((change, i) => (
                            <div key={i} className="flex items-start gap-2">
                                <Badge color={change.contract ? 'red' : 'amber'} variant="outline" size="sm">{change.type === 'added' ? '+' : change.type === 'removed' ? '−' : '~'} {change.source}</Badge>
                                <Text size="xs" color="muted" className="truncate" title={change.detail ? `${change.subject} · ${change.detail}` : change.subject}>
                                    {change.subject}{change.detail && <span className="font-mono"> · {change.detail}</span>}
                                </Text>
                            </div>
                        ))}
                    </div>
                )}
            </Card.Body>
        </Card>
    );
}

const BAND_TONE: Record<Band, 'green' | 'amber' | 'red' | 'zinc' | 'slate'> = {
    green: 'green',
    amber: 'amber',
    red: 'red',
    unknown: 'zinc',
    not_configured: 'slate',
};

const BAND_HEX: Record<Band, string> = { green: '#22c55e', amber: '#f59e0b', red: '#ef4444', unknown: '#a1a1aa', not_configured: '#94a3b8' };

const BAND_LABEL: Record<Band, string> = { green: 'within', amber: 'approaching', red: 'flagged', unknown: UNKNOWN, not_configured: 'not configured' };

function usd(value: number | null): string {
    return value === null ? UNKNOWN : `$${value.toFixed(2)}`;
}

function cellText(label: string, cell: BillingCell, money: boolean): string {
    if (cell.recorded === null || cell.billed === null) return `${label}: ${BAND_LABEL[cell.band]}${cell.reason ? ` (${cell.reason})` : ''}`;
    const fmt = (v: number) => (money ? usd(v) : v.toLocaleString());
    const pct = cell.deltaPercent === null ? 'billed 0' : `${cell.deltaPercent.toFixed(2)}%`;
    return `${label}: recorded ${fmt(cell.recorded)} · billed ${fmt(cell.billed)} · Δ ${pct}`;
}

function BillingCard({ provider }: { provider: BillingProvider }) {
    // Paired bars: recorded (slate) then billed, coloured by the day's cost
    // delta. A day with no compared cost draws no bar: zero would be a reading.
    const bars = provider.days.flatMap(d => (d.cost.recorded === null || d.cost.billed === null
        ? []
        : [
            { label: `${d.day.slice(5)} rec`, value: d.cost.recorded, color: '#64748b' },
            { label: `${d.day.slice(5)} billed`, value: d.cost.billed, color: BAND_HEX[d.cost.band] },
        ]));
    const notConfigured = provider.status === 'not_configured';

    return (
        <Card variant="outlined" padding="sm" data-testid={`billing-provider-${provider.provider}`} data-status={provider.status}>
            <Card.Body className="space-y-3">
                <div className="flex items-center justify-between gap-2">
                    <Text weight="semibold" className="font-mono">{provider.provider}</Text>
                    <Badge color={BAND_TONE[provider.status]} variant="soft" size="sm" dot>
                        {BAND_LABEL[provider.status]}{provider.reason ? ` · ${provider.reason}` : ''}
                    </Badge>
                </div>
                {bars.length === 0
                    ? <Badge color={notConfigured ? 'slate' : 'zinc'} variant="outline" size="sm">{notConfigured ? 'not configured' : `billed cost ${UNKNOWN}`}</Badge>
                    : <Chart.Bar data={bars} height={160} />}
                <div className="flex flex-wrap gap-1" data-testid={`billing-days-${provider.provider}`}>
                    {provider.days.map(d => (
                        <Tooltip key={d.day} content={`${d.day} · ${cellText('cost', d.cost, true)} · ${cellText('tokens', d.tokens, false)}`}>
                            <Badge color={BAND_TONE[d.band]} variant="soft" size="sm" data-band={d.band}>{d.day.slice(5)}</Badge>
                        </Tooltip>
                    ))}
                </div>
            </Card.Body>
        </Card>
    );
}

function PriceTileCard({ tile }: { tile: PriceTile }) {
    return (
        <Card variant="outlined" padding="sm" data-testid={`price-mismatch-${tile.source}-${tile.provider}-${tile.model}`}>
            <Card.Body className="space-y-1">
                <div className="flex items-center justify-between gap-2">
                    <Text weight="semibold" className="truncate font-mono" title={tile.model}>{tile.model}</Text>
                    <Badge color={tile.source === 'harness' ? 'red' : 'amber'} variant="outline" size="sm">{tile.source}</Badge>
                </div>
                <Text size="xs" color="muted">{tile.provider}</Text>
                {tile.reason && <Badge color="zinc" variant="soft" size="sm">{tile.reason === 'not_in_catalogue' ? 'not in catalogue' : `catalogue ${UNKNOWN}`}</Badge>}
                {tile.fields.map(f => (
                    <Text key={f.field} size="xs" className="font-mono">
                        {f.field} {f.configured === null ? 'unset' : f.configured.toFixed(2)} vs {f.catalogue.toFixed(2)} /Mtok
                    </Text>
                ))}
            </Card.Body>
        </Card>
    );
}

const PR_TONE: Record<UpstreamPull['state'], 'green' | 'violet' | 'zinc'> = { open: 'green', merged: 'violet', closed: 'zinc' };

function UpstreamPullsCard({ pulls }: { pulls: Props['upstreamPulls'] }) {
    // Unknown, never zero: a failed search shows no counts at all.
    const count = (value: number | undefined) => (pulls.counts === null || value === undefined ? UNKNOWN : `${value}${pulls.lowerBound ? '+' : ''}`);

    return (
        <Card className="mt-8" data-testid="drift-upstream-pulls">
            <Card.Header>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Heading as="h2" size="md">Our PRs to {pulls.repo}</Heading>
                    <Text size="xs" color="muted">by {pulls.authors.length === 0 ? UNKNOWN : pulls.authors.join(', ')}</Text>
                </div>
            </Card.Header>
            <Card.Body className="space-y-4">
                <Grid cols={4} gap="md" responsive>
                    <Stat value={count(pulls.counts?.open)} label="open" size="lg" />
                    <Stat value={count(pulls.counts?.merged)} label="merged" size="lg" />
                    <Stat value={count(pulls.counts?.closed)} label="closed unmerged" size="lg" />
                    <div className="space-y-1">
                        <Stat value={pulls.mergeRate === null ? (pulls.readable ? '—' : UNKNOWN) : `${pulls.mergeRate}%`} label="merge rate (of decided)" size="lg" />
                        {pulls.readable && pulls.mergedPerWeek.length > 1 && (
                            <Tooltip content={`merged, cumulative, last ${pulls.mergedPerWeek.length} weeks`}>
                                <Chart.Sparkline data={pulls.mergedPerWeek} width={160} height={32} color="#8b5cf6" min={0} />
                            </Tooltip>
                        )}
                    </div>
                </Grid>
                {!pulls.readable
                    ? <Badge color="zinc" variant="soft">pull requests {UNKNOWN}</Badge>
                    : (
                        <div className="divide-y divide-[var(--k-hairline)]">
                            {pulls.pulls.map(pr => (
                                <div key={pr.number} className="flex flex-wrap items-center gap-2 py-1.5" data-testid={`upstream-pr-${pr.number}`} data-state={pr.state}>
                                    <Badge color={PR_TONE[pr.state]} variant="soft" size="sm" dot>{pr.state}</Badge>
                                    <Text size="sm" className="font-mono">#{pr.number}</Text>
                                    <Text size="sm" className="min-w-0 flex-1 truncate" title={pr.title}>{pr.title}</Text>
                                    <Badge color={pr.stale ? 'red' : 'slate'} variant="outline" size="sm">{pr.ageDays}d old{pr.stale ? ' · stale' : ''}</Badge>
                                    <Badge color="slate" variant="outline" size="sm">{pr.idleDays}d since activity</Badge>
                                    {pr.url && <Button href={pr.url} target="_blank" rel="noreferrer" variant="ghost" size="sm">GitHub ↗</Button>}
                                </div>
                            ))}
                        </div>
                    )}
            </Card.Body>
        </Card>
    );
}

function SummaryTile({ testId, label, children }: { testId: string; label: string; children: ReactNode }) {
    return (
        <Card variant="outlined" padding="md" data-testid={`drift-summary-${testId}`}>
            <Card.Body>
                <Stat value={children} label={label} size="lg" />
            </Card.Body>
        </Card>
    );
}

function BigBadge({ status, children }: { status: Status; children: ReactNode }) {
    return <Badge color={TONE[status]} variant="soft" size="lg" className="text-3xl font-extrabold">{children}</Badge>;
}

export default function Drift(props: Props) {
    const { overall, latest, providers, trend, mcp, baseline, upstream, upstreamPulls, billing, prices } = props;

    const runs = trend.runs;
    // react-fancy's StackedBar draws a tick for EVERY label and cannot thin
    // them, and the same strings key its bars and head its tooltip. So the
    // labels stay full (unique, readable on hover) and the built-in x axis is
    // off; a short MM-DD row under the chart marks first, middle and last run.
    const labels = runs.map(run => run.generatedAt.slice(0, 16).replace('T', ' ') + ' UTC');
    const marks = runs.length === 0 ? [] : [...new Set([0, Math.floor((runs.length - 1) / 2), runs.length - 1])].map(i => runs[i].generatedAt.slice(5, 10));

    return (
        <LabShell title="Drift" current="/lab/drift" eyebrow="Drift watch">
            <h1 className="lab-title">Drift</h1>
            <p className="lab-lead">{labBlurb('/lab/drift')}</p>

            <Grid cols={5} gap="md" responsive className="mt-6" data-testid="drift-summary">
                <SummaryTile testId="overall" label={latest.ageHours === null ? 'status' : `run ${latest.ageHours}h ago`}>
                    <BigBadge status={overall}>{overall === 'unknown' ? UNKNOWN : overall}</BigBadge>
                </SummaryTile>
                <SummaryTile testId="changes" label={latest.changeCount === 1 ? 'change' : 'changes'}>
                    {n(latest.changeCount)}
                </SummaryTile>
                <SummaryTile testId="baseline" label={baseline.days === 1 ? 'day since baseline' : 'days since baseline'}>
                    <BigBadge status={baseline.status}>{n(baseline.days)}</BigBadge>
                </SummaryTile>
                <SummaryTile testId="behind" label="behind upstream">{n(upstream.behind)}</SummaryTile>
                <SummaryTile testId="ahead" label="ahead of upstream">{n(upstream.ahead)}</SummaryTile>
            </Grid>

            <Heading as="h2" size="lg" className="mt-8 mb-3">Providers</Heading>
            {providers.length === 0
                ? <Badge color="zinc" variant="soft">providers {UNKNOWN}</Badge>
                : <Grid cols={4} gap="md" responsive>{providers.map(tile => <ProviderCard key={tile.name} tile={tile} />)}</Grid>}

            <Grid cols={2} gap="md" responsive className="mt-8">
                <Card data-testid="drift-trend">
                    <Card.Header>
                        <div className="flex items-center justify-between gap-2">
                            <Heading as="h2" size="md">Changes per run</Heading>
                            <div className="flex gap-1">
                                <Badge color="red" variant="soft" size="sm" dot>contract</Badge>
                                <Badge color="amber" variant="soft" size="sm" dot>catalogue</Badge>
                                {trend.unreadable > 0 && <Badge color="zinc" variant="outline" size="sm">{trend.unreadable} unreadable</Badge>}
                            </div>
                        </div>
                    </Card.Header>
                    <Card.Body>
                        {runs.length === 0
                            ? <Badge color="zinc" variant="soft">trend {UNKNOWN}</Badge>
                            : <Chart.StackedBar
                                height={220}
                                labels={labels}
                                series={[
                                    { label: 'contract', data: runs.map(run => run.contractCount), color: '#ef4444' },
                                    { label: 'catalogue', data: runs.map(run => run.catalogueCount), color: '#f59e0b' },
                                ]}
                                tooltip
                                responsive
                                xAxis={false}
                                grid={{ horizontal: true, vertical: false }}
                            />}
                        {marks.length > 0 && (
                            <div className="flex justify-between px-10" data-testid="drift-trend-dates">
                                {marks.map((mark, i) => <Text key={i} size="xs" color="muted" className="font-mono">{mark}</Text>)}
                            </div>
                        )}
                    </Card.Body>
                </Card>

                <Card data-testid="drift-upstream">
                    <Card.Header>
                        <Heading as="h2" size="md">Upstream · {upstream.repo}</Heading>
                    </Card.Header>
                    <Card.Body className="space-y-4">
                        <Grid cols={2} gap="md">
                            <Stat value={n(upstream.behind)} label="behind (upstream commits we lack)" size="lg" />
                            <Stat value={n(upstream.ahead)} label="ahead (our commits)" size="lg" />
                        </Grid>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge color="slate" variant="outline">upstream {upstream.upstreamRelease ?? UNKNOWN}</Badge>
                            <Badge color="indigo" variant="outline">ours {upstream.forkRelease ?? UNKNOWN}</Badge>
                        </div>
                        <Grid cols={2} gap="md">
                            <Stat value={watched(upstream.openPulls)} label="open upstream PRs" size="sm" />
                            <Stat value={watched(upstream.openIssues)} label="open upstream issues" size="sm" />
                        </Grid>
                    </Card.Body>
                </Card>
            </Grid>

            <Card className="mt-8" data-testid="drift-mcp">
                <Card.Header>
                    <Heading as="h2" size="md">MCP schema coverage</Heading>
                </Card.Header>
                <Card.Body className="flex flex-wrap items-center gap-2">
                    {!mcp.readable || mcp.versions.length === 0
                        ? <Badge color="zinc" variant="soft">coverage {UNKNOWN}</Badge>
                        : mcp.versions.map(v => (
                            v.gapServers.length === 0
                                ? <StatusBadge key={v.version} status={v.status} size="lg">{v.version}</StatusBadge>
                                : <Tooltip key={v.version} content={`not served by ${v.gapServers.join(', ')}`}>
                                    <Badge color={TONE[v.status]} variant="soft" size="lg" dot>{v.version}</Badge>
                                </Tooltip>
                        ))}
                    {mcp.unreadableServers.map(server => <Badge key={server} color="red" variant="outline" size="sm">{server} unreadable</Badge>)}
                </Card.Body>
            </Card>

            <UpstreamPullsCard pulls={upstreamPulls} />

            <Card className="mt-8" data-testid="drift-billing">
                <Card.Header>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading as="h2" size="md">Recorded vs billed</Heading>
                        <Text size="xs" color="muted">
                            last {billing.providers[0]?.days.length ?? 0} closed UTC days · flagged over {billing.thresholdPercent}% and {usd(billing.floorUsd)} / {billing.floorTokens.toLocaleString()} tokens · {billing.graceHours}h grace
                        </Text>
                    </div>
                </Card.Header>
                <Card.Body>
                    <Grid cols={2} gap="md" responsive>
                        {billing.providers.map(p => <BillingCard key={p.provider} provider={p} />)}
                    </Grid>
                </Card.Body>
            </Card>

            <Card className="mt-8" data-testid="drift-prices">
                <Card.Header>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading as="h2" size="md">Configured vs catalogue prices</Heading>
                        <div className="flex flex-wrap gap-1">
                            <Badge color={prices.status === 'ok' ? 'green' : prices.status === 'mismatch' ? 'red' : 'zinc'} variant="soft" size="sm" dot>
                                {prices.status === 'unknown' ? `catalogue ${UNKNOWN}` : prices.status === 'ok' ? 'matching' : `${prices.tiles.length} mismatched`}
                            </Badge>
                            {prices.harnessConfiguredModels === 0 && <Badge color="slate" variant="outline" size="sm">harness rates not configured</Badge>}
                            {prices.unreadableProviders.map(p => <Badge key={p} color="zinc" variant="outline" size="sm">{p} prices {UNKNOWN}</Badge>)}
                        </div>
                    </div>
                </Card.Header>
                {prices.tiles.length > 0 && (
                    <Card.Body>
                        <Grid cols={4} gap="md" responsive>
                            {prices.tiles.map(tile => <PriceTileCard key={`${tile.source}-${tile.provider}-${tile.model}`} tile={tile} />)}
                        </Grid>
                    </Card.Body>
                )}
            </Card>
        </LabShell>
    );
}
