import { Link, router } from '@inertiajs/react';
import { LabShell } from '../../components/lab-shell';

type Spec = { id: string; name: string; revision: number; status: string; digest: string; archetype: string; surface_mode: string; lane_matrix: unknown[] };
type Run = { id: string; status: string; learning_ref?: string | null; spec: Spec };

export default function Benchmarks({ specs, runs, providerAggregateCount }: { specs: Spec[]; runs: Run[]; providerAggregateCount: number }) {
    const clearRuns = (scope: 'queued' | 'settled') => {
        const label = scope === 'queued' ? 'every queued run' : 'all completed, failed, and cancelled run history';
        if (window.confirm(`Delete ${label}? This permanently removes lane proof and Fancy Flow records.`)) {
            router.delete('/lab/benchmarks/runs', { data: { scope }, preserveScroll: true });
        }
    };
    const deleteRun = (run: Run) => {
        if (window.confirm(`Delete run ${run.id}? Its lane proof and workflow records will be permanently removed.`)) {
            router.delete(`/lab/benchmarks/runs/${run.id}`, { preserveScroll: true });
        }
    };
    return <LabShell title="Benchmark Studio" current="/lab/benchmarks" eyebrow="Benchmark Studio · durable Fancy Flow orchestration">
        <div className="lab-page-heading"><div><h1 className="lab-title">Design tests with PLab.</h1><p className="lab-lead">Discuss what you want to learn in the PLab Agent flyout. PLab turns the conversation into a fair, reviewable specification—then you decide whether to freeze and run it.</p></div><button type="button" className="plab-agent-presence" onClick={() => window.dispatchEvent(new Event('plab:open'))}><i /><span>Open PLab Agent</span></button></div>
        <section className="lab-studio-grid">
            <aside className="lab-panel lab-steps"><b>PLab’s design process</b><span className="is-active">01 Understand the question</span><span>02 Define evidence</span><span>03 Design the rubric</span><span>04 Choose lanes &amp; budgets</span><span>05 Propose a draft</span></aside>
            <div className="lab-panel"><div className="lab-panel-head"><span>Drafts and frozen specifications</span><span>{specs.length} recent</span></div>{specs.length === 0 ? <p className="lab-empty">No benchmark specification exists yet. Create a revisioned draft; launch remains unavailable until approval freezes its digest.</p> : specs.map(spec => <Link href={`/lab/benchmarks/specs/${spec.id}`} className="lab-spec" key={spec.id}><div><b>{spec.name}</b><small>rev {spec.revision} · {spec.archetype} · {spec.surface_mode.replace('_', '+')}</small><code>{spec.digest.slice(0, 12)}…</code></div><div><span className="lab-status">{spec.status}</span><small>{spec.lane_matrix.length} lanes · Review all details →</small></div></Link>)}</div>
            <aside className="lab-panel"><div className="lab-panel-head"><span>Launch policy</span></div><Gate title="Specification frozen" text="Immutable digest and explicit human approval." /><Gate title="Fair lane matrix" text="Same spec, randomized identity, isolated workspace." /><Gate title="Hard budgets" text="Tokens, spend, elapsed time, and turn ceilings." /></aside>
        </section>
        <section className="lab-panel" style={{ marginTop: '.85rem' }}><div className="lab-panel-head"><span>Run room</span><div className="lab-run-actions"><button type="button" className="k-btn k-btn--ghost k-btn--small" onClick={() => clearRuns('queued')}>Clear queued</button><button type="button" className="k-btn k-btn--ghost k-btn--small" onClick={() => clearRuns('settled')}>Clear history</button><span>{runs.length} recent runs</span></div></div>{runs.length === 0 ? <p className="lab-empty">No benchmark has launched. Approved specifications appear here as durable per-lane runs.</p> : runs.map(run => <div className="lab-run" key={run.id}><i /><Link href={`/lab/benchmarks/runs/${run.id}`}><b>{run.spec.name}</b><small>{run.id} · revision {run.spec.revision}{run.learning_ref ? ` · ${run.learning_ref}` : ''}</small></Link><span className="lab-status">{run.status}</span>{!['running', 'ready'].includes(run.status) && <button type="button" className="lab-icon-button" aria-label={`Delete run ${run.id}`} onClick={() => deleteRun(run)}>Delete</button>}</div>)}</section>
        <p className="lab-diagnostic-note">The former provider latency aggregate ({providerAggregateCount} recorded test runs) is retained under <Link href="/lab/diagnostics">Diagnostics</Link>; it is not a PLabs benchmark.</p>
    </LabShell>;
}

function Gate({ title, text }: { title: string; text: string }) { return <div className="lab-gate"><b>{title}</b><small>{text}</small></div>; }
