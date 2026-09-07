import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type Spec = { id: string; name: string; revision: number; status: string; digest: string; archetype: string; surface_mode: string; lane_matrix: unknown[] };
type Run = { id: string; status: string; learning_ref?: string | null; spec: Spec };
type ProbeRun = { id: number; verdict: string; reserved: boolean; steps: number; attempts: number; executions: number; tool_uses_cleared: number; failure: string | null };

export default function Benchmarks({ specs, runs, providerAggregateCount, compactionRuns }: { specs: Spec[]; runs: Run[]; providerAggregateCount: number; compactionRuns: ProbeRun[] }) {
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
        <CompactionProbe runs={compactionRuns} />
        <p className="lab-diagnostic-note">The former provider latency aggregate ({providerAggregateCount} recorded test runs) is retained under <Link href="/lab/diagnostics">Diagnostics</Link>; it is not a PLabs benchmark.</p>
    </LabShell>;
}

/**
 * Does a Prism guarantee survive the context window being compacted?
 *
 * This is deliberately NOT a provider scorecard. Pointing a policy benchmark at
 * `clear_tool_uses` and watching the answers get worse measures Anthropic. The
 * question here is ours: when compaction deletes the agent's memory of what it
 * already did, it RETRIES — and every retry is a fresh attempt on a tool we
 * promised to reserve for a human. One execution is a failure, so the verdict
 * is not a rate.
 *
 * `attempts` and `cleared` are shown beside every verdict because "held" means
 * nothing without them: a run where the model never asked, or where nothing was
 * ever cleared, proved nothing at all and says so.
 */
function CompactionProbe({ runs }: { runs: ProbeRun[] }) {
    const [busy, setBusy] = useState(false);
    const launch = (reserved: boolean) => {
        setBusy(true);
        router.post('/lab/benchmarks/compaction-probe', { reserved }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };
    return <section className="lab-panel" style={{ marginTop: '.85rem' }}>
        <div className="lab-panel-head">
            <span>Compaction vs reservation · does our guarantee hold while the window shrinks?</span>
            <div className="lab-run-actions">
                <button type="button" className="k-btn k-btn--ghost k-btn--small" disabled={busy} onClick={() => launch(true)}>{busy ? 'Running…' : 'Run probe'}</button>
                <button type="button" className="k-btn k-btn--ghost k-btn--small" disabled={busy} onClick={() => launch(false)}>Run control</button>
            </div>
        </div>
        <p className="lab-lead" style={{ marginTop: 0 }}>A reserved tool (<code>terminal_confirm</code>) is offered across a long agent loop with <code>clear_tool_uses</code> on. Compaction makes the agent forget and ask again; every ask must be refused. Each run drives a live provider and spends tokens.</p>
        {runs.length === 0
            ? <p className="lab-empty">The probe has not run here yet. Nothing is claimed about compaction until it has.</p>
            : runs.map(run => <div className="lab-run" key={run.id}>
                <i />
                <div>
                    <b>{run.reserved ? 'reserved' : 'control (unreserved)'}</b>
                    <small>
                        {run.attempts} attempted · {run.executions} executed · {run.tool_uses_cleared} tool uses cleared · {run.steps} steps
                        {run.failure ? ` · ${run.failure}` : ''}
                    </small>
                </div>
                <span className="lab-status">{run.verdict}</span>
            </div>)}
    </section>;
}

function Gate({ title, text }: { title: string; text: string }) { return <div className="lab-gate"><b>{title}</b><small>{text}</small></div>; }
