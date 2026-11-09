import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import { requestPaidRun } from '../../lib/paid-run';

type Lane = { language: string; harness: string; provider: string; model: string };
type RubricDimension = { name?: string; weight?: number; criteria?: string };
type Spec = { id: string; name: string; revision: number; digest: string; status: string; archetype: string; surface_mode: string; specification: { outcome?: string; acceptance?: string[]; acceptance_criteria?: string[]; constraints?: string[] }; rubric: Record<string, unknown>; lane_matrix: Lane[]; budgets: Record<string, unknown>; approved_at?: string | null; created_at: string };

export default function SpecificationReview({ spec }: { spec: Spec }) {
    const acceptance = spec.specification.acceptance ?? spec.specification.acceptance_criteria ?? [];
    const dimensions = rubricDimensions(spec.rubric);
    return <LabShell title={`${spec.name} · Specification`} current="/lab/benchmarks" eyebrow={`Specification review · revision ${spec.revision}`}>
        <div className="lab-page-heading"><div><h1 className="lab-title">{spec.name}</h1><p className="lab-lead">Review the entire frozen contract before approving spend or launching agents.</p></div><div className="lab-review-actions"><Link href="/lab/benchmarks" className="k-btn k-btn--ghost">Back to Studio</Link><SpecAction spec={spec} /></div></div>
        <section className="lab-spec-meta"><Meta label="State" value={spec.status} /><Meta label="Revision" value={String(spec.revision)} /><Meta label="Surface" value={spec.surface_mode.replace('_', '+')} /><Meta label="Archetype" value={spec.archetype} /><Meta label="Approved" value={spec.approved_at ?? 'Not approved'} /></section>
        <section className="lab-review-grid">
            <div className="lab-panel lab-review-main"><Section title="Product outcome"><p>{spec.specification.outcome ?? 'No outcome recorded.'}</p></Section>{(spec.specification.constraints ?? []).length > 0 && <Section title="Constraints"><ul>{spec.specification.constraints?.map((constraint, index) => <li key={index}>{constraint}</li>)}</ul></Section>}<Section title="Acceptance checks"><ol className="lab-check-list">{acceptance.map((check, index) => <li key={index}><span>{index + 1}</span>{check}</li>)}</ol></Section><Section title="Surface policy"><p>{spec.surface_mode === 'human_plus' ? 'Each lane must operate the Fancy application through an active Human+ browser surface, whether or not a person is present.' : 'Each lane uses the guarded headless Browser capability. Human+ is not required for this benchmark.'}</p></Section></div>
            <aside className="lab-panel"><div className="lab-panel-head"><span>Scoring rubric</span><span>{formatWeight(dimensions.reduce((sum, item) => sum + item.weight, 0))} total</span></div>{dimensions.map((dimension, index) => <div className="lab-rubric" key={`${dimension.name}-${index}`}><span>{dimension.name}{dimension.criteria && <small>{dimension.criteria}</small>}</span><b>{formatWeight(dimension.weight)}</b></div>)}</aside>
        </section>
        <section className="lab-panel" style={{ marginTop: '.85rem' }}><div className="lab-panel-head"><span>Identical lane matrix</span><span>{spec.lane_matrix.length} agents</span></div><div className="lab-lane-table"><b>Language</b><b>Harness</b><b>Provider</b><b>Model</b>{spec.lane_matrix.map((lane, index) => <LaneRow lane={lane} key={`${lane.language}-${index}`} />)}</div></section>
        <section className="lab-review-grid"><div className="lab-panel"><div className="lab-panel-head"><span>Hard budgets</span></div>{Object.entries(spec.budgets).map(([name, value]) => <div className="lab-rubric" key={name}><span>{name.replaceAll('_', ' ')}</span><b>{displayValue(value)}</b></div>)}</div><div className="lab-panel"><div className="lab-panel-head"><span>Immutable identity</span></div><p className="lab-digest">{spec.digest}</p><p className="lab-diagnostic-note">Approval applies to this exact digest. Any material change must create another revision.</p></div></section>
    </LabShell>;
}

function Section({ title, children }: { title: string; children: React.ReactNode }) { return <section><h2>{title}</h2>{children}</section>; }
function Meta({ label, value }: { label: string; value: string }) { return <div><small>{label}</small><b>{value}</b></div>; }
function LaneRow({ lane }: { lane: Lane }) { return <><span>{lane.language}</span><span>{lane.harness}</span><span>{lane.provider}</span><span>{lane.model}</span></>; }
function SpecAction({ spec }: { spec: Spec }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const action = spec.status === 'draft' ? 'request-approval' : spec.status === 'awaiting_approval' ? 'approve' : spec.status === 'approved' ? 'launch' : null;
    const label = spec.status === 'draft' ? 'Request freeze review' : spec.status === 'awaiting_approval' ? 'Approve frozen spec' : spec.status === 'approved' ? 'Launch all lanes' : null;
    if (!action || !label) return null;
    const path = `/lab/benchmarks/${spec.id}/${action}`;
    async function launch() {
        setBusy(true); setError(null);
        try {
            const id = await requestPaidRun(path, {});
            router.post(path, {}, { headers: { 'X-Lab-Run-Id': id }, onFinish: () => setBusy(false) });
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'The run was not launched.'); setBusy(false); }
    }
    return <div data-agent-off-limits={action === 'request-approval' ? undefined : 'consent'}>
        {action === 'launch' ? <button type="button" className="k-btn k-btn--grad" disabled={busy} onClick={() => { void launch(); }}>{label}</button>
            : <Link as="button" method="post" href={path} className="k-btn k-btn--grad">{label}</Link>}
        {error && <p role="alert">{error}</p>}
    </div>;
}

function rubricDimensions(rubric: Record<string, unknown>): Array<{ name: string; weight: number; criteria?: string }> {
    if (Array.isArray(rubric.dimensions)) {
        return (rubric.dimensions as RubricDimension[]).map((item, index) => ({ name: item.name ?? `Dimension ${index + 1}`, weight: Number(item.weight ?? 0), criteria: item.criteria }));
    }
    return Object.entries(rubric).filter(([, value]) => typeof value === 'number').map(([name, weight]) => ({ name: name.replaceAll('_', ' '), weight: Number(weight) }));
}

function formatWeight(value: number): string { return value <= 1 ? `${Math.round(value * 100)}%` : String(value); }
function displayValue(value: unknown): string { return typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '—'); }
