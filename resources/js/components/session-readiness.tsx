import { Badge, Card } from '@particle-academy/react-fancy';

type Check = { ready: boolean; status: string; message: string };
export type SessionPreflightReport = {
    ready: boolean;
    checked_at: string;
    notice: string;
    checks: {
        workers: Check & { queues: { queue: string; status: string }[] };
        database: Check;
        providers: Check & { providers: { key: string; label: string; presence: string; required: boolean }[] };
        core_actions: Check & { head: string | null; checked_at: number | null };
    };
};

const labels = { workers: 'Queue workers', database: 'Lab database', providers: 'Provider credentials', core_actions: 'Core Actions' };

export function SessionReadiness({ report }: { report: SessionPreflightReport }) {
    return <Card className="mb-8" data-testid="session-readiness">
        <Card.Header>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-semibold">Session preflight</h2>
                <Badge color={report.ready ? 'green' : 'amber'} variant="soft">{report.ready ? 'Ready for approval' : 'Setup required'}</Badge>
            </div>
        </Card.Header>
        <Card.Body>
            <p className="mb-4 text-sm text-muted-foreground">{report.notice}</p>
            <dl className="grid gap-4 sm:grid-cols-2">
                {Object.entries(report.checks).map(([key, check]) => <div key={key} data-check={key}>
                    <dt className="flex items-center gap-2 font-medium">{labels[key as keyof typeof labels]}
                        <Badge color={check.ready ? 'green' : 'amber'} size="sm" variant="outline">{check.status}</Badge>
                    </dt>
                    <dd className="mt-1 text-sm text-muted-foreground">{check.message}</dd>
                </div>)}
            </dl>
            <ul className="mt-4 flex flex-wrap gap-2" aria-label="Provider key presence">
                {report.checks.providers.providers.map(provider => <li key={provider.key}>
                    <Badge color={provider.presence === 'present' ? 'green' : 'zinc'} variant="soft" size="sm">
                        {provider.label}{provider.required ? ' (coordinator)' : ''}: {provider.presence.toUpperCase().replaceAll('_', ' ')}
                    </Badge>
                </li>)}
            </ul>
            <p className="mt-4 text-xs text-muted-foreground">Snapshot: <time dateTime={report.checked_at}>{report.checked_at}</time>.
                {report.checks.core_actions.head && <> Core main observed: <code className="break-all">{report.checks.core_actions.head}</code> at {new Date((report.checks.core_actions.checked_at ?? 0) * 1000).toISOString()}.</>}
                {' '}<a className="underline" href="/lab/preflight">Refresh status as JSON</a>
            </p>
        </Card.Body>
    </Card>;
}
