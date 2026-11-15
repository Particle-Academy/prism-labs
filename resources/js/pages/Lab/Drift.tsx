import { Badge, Button, Card, Chart, Grid, Heading, Stat, Text, Tooltip } from '@particle-academy/react-fancy';
import { type ReactNode, useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import { labBlurb } from '../../components/lab-nav';

type Status = 'green' | 'amber' | 'red' | 'unknown';

type ProviderChange = { source: string; type: string; subject: string; contract: boolean };

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
                                <Text size="xs" color="muted" className="truncate" title={change.subject}>{change.subject}</Text>
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
    const { overall, latest, providers, trend, mcp, baseline, upstream } = props;

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
        </LabShell>
    );
}
