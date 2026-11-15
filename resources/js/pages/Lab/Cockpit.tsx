import { Link } from '@inertiajs/react';
import { LabShell } from '../../components/lab-shell';
import { SessionReadiness, type SessionPreflightReport } from '../../components/session-readiness';

type Run = { id: number; status: string; spec?: { name?: string; title?: string } | null; created_at?: string };
type Consensus = { id: number; topic?: string; question?: string; status: string };
type Operation = { id: number; kind: string; provider?: string | null; model?: string | null; status: string; duration_ms?: number | null; cost?: number | null };

export default function Cockpit({ preflight, dataAvailable, metrics, benchmarks, consensus, recent }: {
    preflight: SessionPreflightReport; dataAvailable: boolean;
    metrics: { activeRuns: number; dailyTokens: number; dailyCost: number; operations: number } | null;
    benchmarks: Run[]; consensus: Consensus[]; recent: Operation[];
}) {
    return <LabShell title="Operations Cockpit" current="/lab" eyebrow="Operations cockpit · local">
        <h1 className="lab-title">Lab session readiness</h1>
        <p className="lab-lead">Check setup before preparing work for approval.</p>
        <SessionReadiness report={preflight} />
        <section className="lab-metrics">
            <Metric label="Active runs" value={metrics?.activeRuns.toLocaleString() ?? 'Unavailable'} detail="consensus + benchmark workflows" />
            <Metric label="Today" value={metrics?.dailyTokens.toLocaleString() ?? 'Unavailable'} detail="measured tokens" />
            <Metric label="Daily cost" value={metrics ? `$${metrics.dailyCost.toFixed(2)}` : 'Unavailable'} detail="priced operation ledger" />
            <Metric label="Operations" value={metrics?.operations.toLocaleString() ?? 'Unavailable'} detail="all instrumented work" />
        </section>
        <section className="lab-dashboard-grid">
            <div className="lab-panel">
                <div className="lab-panel-head"><span>Workflow queue</span><Link href="/lab/benchmarks">Open studio →</Link></div>
                {!dataAvailable ? <Empty text="Workflow data is unavailable until the Lab database is ready." /> : benchmarks.length === 0 && consensus.length === 0 && <Empty text="No workflows yet. Design a benchmark or request parity consensus." />}
                {benchmarks.map(run => <RunRow key={`b${run.id}`} kind="Benchmark" title={run.spec?.name ?? run.spec?.title ?? `Run ${run.id}`} status={run.status} />)}
                {consensus.map(run => <RunRow key={`c${run.id}`} kind="Consensus" title={run.topic ?? run.question ?? `Run ${run.id}`} status={run.status} />)}
            </div>
            <div className="lab-panel">
                <div className="lab-panel-head"><span>Latest activity</span><Link href="/lab/telemetry">Full ledger →</Link></div>
                {!dataAvailable ? <Empty text="Activity data is unavailable until the Lab database is ready." /> : recent.length === 0 && <Empty text="The telemetry sidecar has not recorded an operation yet." />}
                {recent.map(op => <div className="lab-activity" key={op.id}><i className={op.status === 'completed' ? 'ok' : ''} /><div><b>{op.kind}</b><small>{[op.provider, op.model].filter(Boolean).join(' · ') || 'local operation'}</small></div><span>{op.cost == null ? `${op.duration_ms ?? 0} ms` : `$${Number(op.cost).toFixed(4)}`}</span></div>)}
            </div>
        </section>
    </LabShell>;
}

function Metric({ label, value, detail }: { label: string; value: string; detail: string }) {
    return <div className="lab-panel lab-metric"><small>{label}</small><strong>{value}</strong><span>{detail}</span></div>;
}
function RunRow({ kind, title, status }: { kind: string; title: string; status: string }) {
    return <div className="lab-run"><i /><div><b>{title}</b><small>{kind}</small></div><span className="lab-status">{status}</span></div>;
}
function Empty({ text }: { text: string }) { return <p className="lab-empty">{text}</p>; }
