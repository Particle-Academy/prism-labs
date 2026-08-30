import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type Run = { id: string; question: string; status: string; synthesis?: string | null; evidence_digest: string; reviewed_at?: string | null };

export default function Consensus({ runs }: { runs: Run[] }) {
    const [creating, setCreating] = useState(false);
    const [selected, setSelected] = useState<Run | null>(runs.find(run => run.status === 'awaiting_review') ?? runs[0] ?? null);
    const request = useForm({ question: '', evidence: '' });
    const review = useForm({ synthesis: selected?.synthesis ?? '' });

    function choose(run: Run) { setSelected(run); review.setData('synthesis', run.synthesis ?? ''); }

    return <LabShell title="Parity Consensus" current="/lab/consensus" eyebrow="Consensus workspace · independent parity responses">
        <div className="lab-page-heading"><div><h1 className="lab-title">Agreement without erasing dissent.</h1><p className="lab-lead">Collect independent language-agent answers, review the synthesis, then publish a preserved artifact.</p></div><button className="k-btn k-btn--grad" onClick={() => setCreating(value => !value)}>{creating ? 'Close request' : 'Request consensus'}</button></div>
        {creating && <form className="lab-panel lab-draft-form" onSubmit={event => { event.preventDefault(); request.post('/lab/consensus', { onSuccess: () => { request.reset(); setCreating(false); } }); }}><Field label="Question" error={request.errors.question}><textarea className="lab-input" rows={5} value={request.data.question} onChange={e => request.setData('question', e.target.value)} /></Field><Field label="Evidence brief · optional" error={request.errors.evidence}><textarea className="lab-input" rows={4} value={request.data.evidence} onChange={e => request.setData('evidence', e.target.value)} /></Field><p className="lab-diagnostic-note">Each available parity agent receives the same question and evidence. Collection may take several minutes.</p><button className="k-btn k-btn--grad" disabled={request.processing}>{request.processing ? 'Collecting responses…' : 'Start collection'}</button></form>}
        <section className="lab-consensus-grid">
            <div className="lab-panel"><div className="lab-panel-head"><span>Consensus queue</span><span>{runs.length} runs</span></div>{runs.length === 0 ? <p className="lab-empty">No consensus has been requested.</p> : runs.map(run => <button className={`lab-consensus-row ${selected?.id === run.id ? 'is-active' : ''}`} key={run.id} onClick={() => choose(run)}><span>{run.question}</span><small>{run.status} · {run.evidence_digest.slice(0, 10)}…</small></button>)}</div>
            <div className="lab-panel">{selected ? <><div className="lab-panel-head"><span>Review artifact</span><span className="lab-status">{selected.status}</span></div><h2 className="lab-review-question">{selected.question}</h2>{selected.status === 'awaiting_review' ? <form onSubmit={event => { event.preventDefault(); review.post(`/lab/consensus/${selected.id}/review`); }}><Field label="Prism.php synthesis · dissent must remain explicit" error={review.errors.synthesis}><textarea className="lab-input" rows={12} value={review.data.synthesis} onChange={e => review.setData('synthesis', e.target.value)} /></Field><button className="k-btn k-btn--grad mt-4" disabled={review.processing}>Mark reviewed</button></form> : <div className="lab-synthesis">{selected.synthesis ?? 'Collection is still in progress.'}</div>}</> : <p className="lab-empty">Choose a run to inspect and review.</p>}</div>
        </section>
    </LabShell>;
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) { return <label><span className="k-mono mb-2 block">{label}</span>{children}{error && <small className="text-[var(--k-mag)]">{error}</small>}</label>; }
