import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type AgentResponse = { id: string; agent: string; language: string; status: string; answer: string | null; evidence: unknown; confidence: string | null; dissent: string | null };
type Tally = { agents: number; responded: number; unavailable: number; dissenting: number };
type RunLearning = { ref: string; title: string; severity: string; severity_label: string; what_was_learned: string; evidence: string; why_it_matters: string; what_should_change: string | null; path: string };
type Run = {
    id: string; question: string; status: string; synthesis: string | null; evidence_digest: string;
    reviewed_at: string | null; abandoned_at: string | null; abandon_reason: string | null; created_at: string | null;
    responses: AgentResponse[]; tally: Tally; learning: RunLearning | null;
};

export default function Consensus({ runs }: { runs: Run[] }) {
    const [creating, setCreating] = useState(false);

    // Selection is held as an ID and the run is looked up from the CURRENT
    // props on every render. Holding the run object itself meant the panel
    // kept rendering the snapshot it was clicked with: submit a review and the
    // status badge, the synthesis and the freshly filed 0L were all still the
    // pre-review values until a full reload.
    const [selectedId, setSelectedId] = useState<string | null>(runs.find(run => run.status === 'awaiting_review')?.id ?? runs[0]?.id ?? null);
    const selected = runs.find(run => run.id === selectedId) ?? runs[0] ?? null;

    const request = useForm({ question: '', evidence: '' });
    const review = useForm({ synthesis: '' });
    const abandon = useForm({ reason: '' });

    function choose(run: Run) {
        setSelectedId(run.id);
        review.setData('synthesis', run.synthesis ?? '');
        abandon.setData('reason', '');
    }

    return <LabShell title="Parity Consensus" current="/lab/consensus" eyebrow="Consensus workspace · independent parity responses">
        <div className="lab-page-heading">
            <div>
                <h1 className="lab-title">Agreement without erasing dissent.</h1>
                <p className="lab-lead">Collect independent language-agent answers, review the synthesis, then publish a preserved artifact. Every run that ends files a 0Learning — including the ones that collected nothing.</p>
            </div>
            <button className="k-btn k-btn--grad" onClick={() => setCreating(value => !value)}>{creating ? 'Close request' : 'Request consensus'}</button>
        </div>

        {creating && <form className="lab-panel lab-draft-form" onSubmit={event => { event.preventDefault(); request.post('/lab/consensus', { onSuccess: () => { request.reset(); setCreating(false); } }); }}>
            <Field label="Question" error={request.errors.question}><textarea className="lab-input" rows={5} value={request.data.question} onChange={e => request.setData('question', e.target.value)} /></Field>
            <Field label="Evidence brief · optional" error={request.errors.evidence}><textarea className="lab-input" rows={4} value={request.data.evidence} onChange={e => request.setData('evidence', e.target.value)} /></Field>
            <p className="lab-diagnostic-note">Each available parity agent receives the same question and evidence. Collection may take several minutes.</p>
            <button className="k-btn k-btn--grad" disabled={request.processing}>{request.processing ? 'Collecting responses…' : 'Start collection'}</button>
        </form>}

        <section className="lab-consensus-grid">
            <div className="lab-panel">
                <div className="lab-panel-head"><span>Consensus queue</span><span>{runs.length} runs</span></div>
                {runs.length === 0
                    ? <p className="lab-empty">No consensus has been requested.</p>
                    : runs.map(run => <button className={`lab-consensus-row ${selected?.id === run.id ? 'is-active' : ''}`} key={run.id} onClick={() => choose(run)}>
                        <span>{run.question}</span>
                        <small>{run.status} · {run.tally.responded}/{run.tally.agents} answered{run.tally.dissenting > 0 ? ` · ${run.tally.dissenting} dissent` : ''}{run.learning ? ` · ${run.learning.ref}` : ''}</small>
                    </button>)}
            </div>

            <div className="lab-panel">
                {selected ? <>
                    <div className="lab-panel-head"><span>Review artifact</span><span className="lab-status">{selected.status}</span></div>
                    <h2 className="lab-review-question">{selected.question}</h2>
                    <Tallies tally={selected.tally} />
                    <Responses responses={selected.responses} />
                    {selected.status === 'awaiting_review'
                        ? <>
                            <form onSubmit={event => { event.preventDefault(); review.post(`/lab/consensus/${selected.id}/review`); }}>
                                <Field label="Prism.php synthesis · dissent must remain explicit" error={review.errors.synthesis}>
                                    <textarea className="lab-input" rows={12} value={review.data.synthesis} onChange={e => review.setData('synthesis', e.target.value)} />
                                </Field>
                                <button className="k-btn k-btn--grad mt-4" disabled={review.processing}>Mark reviewed</button>
                            </form>
                            {/* Confirmed, because abandonment is terminal: the run
                                can never be reviewed afterwards, and the 0L it
                                files says so permanently. */}
                            <form className="lab-abandon" onSubmit={event => {
                                event.preventDefault();
                                if (window.confirm('Close this run unreviewed? It can never be synthesised afterwards, and the 0Learning it files will record that no conclusion was drawn from these answers.')) {
                                    abandon.post(`/lab/consensus/${selected.id}/abandon`);
                                }
                            }}>
                                <b>Nobody is going to synthesise this</b>
                                <p>Closing it records what the agents said and that no conclusion was drawn. Leaving it here records nothing at all — the calls were still spent.</p>
                                <Field label="Why · optional, and it goes into the 0Learning verbatim" error={abandon.errors.reason}>
                                    <input className="lab-input" value={abandon.data.reason} onChange={e => abandon.setData('reason', e.target.value)} />
                                </Field>
                                <button className="k-btn k-btn--ghost mt-4" disabled={abandon.processing}>Abandon without review</button>
                            </form>
                        </>
                        : <div className="lab-synthesis">{selected.synthesis ?? (selected.status === 'abandoned'
                            ? `Abandoned unreviewed. ${selected.abandon_reason ?? 'No reason was given.'}`
                            : 'Collection is still in progress.')}</div>}
                    <Learning learning={selected.learning} status={selected.status} />
                </> : <p className="lab-empty">Choose a run to inspect and review.</p>}
            </div>
        </section>
    </LabShell>;
}

/**
 * What the roster DID, counted — and deliberately not scored.
 *
 * Nothing here compares two natural-language answers for meaning, so
 * "agreement" is not a number this surface is entitled to print — there is no
 * rubric and no cited receipt behind one. The only disagreement shown is the
 * kind an agent declared for itself; the verdict is the human synthesis below.
 */
function Tallies({ tally }: { tally: Tally }) {
    return <div className="lab-tally">
        <span><b>{tally.responded}</b> of {tally.agents} agents answered</span>
        {tally.unavailable > 0 && <span className="is-warn"><b>{tally.unavailable}</b> unreachable</span>}
        <span className={tally.dissenting > 0 ? 'is-dissent' : ''}><b>{tally.dissenting}</b> declared dissent</span>
        <small>PLabs does not judge whether the answers agree. It reports what each agent declared.</small>
    </div>;
}

/**
 * The opinions themselves — the half of this page that did not exist.
 *
 * `consensus_responses` rows carried every agent's answer, stated confidence
 * and dissent from the first build, and the page rendered the question, the
 * status and nothing else. A surface whose entire purpose is "agreement
 * without erasing dissent" was erasing all of it.
 */
function Responses({ responses }: { responses: AgentResponse[] }) {
    if (responses.length === 0) {
        return <p className="lab-empty">No agent was addressable when this run was collected, so nothing was asked.</p>;
    }

    return <div className="lab-responses">
        {responses.map(response => <article key={response.id} className={`lab-response is-${response.status}`}>
            <header>
                <div><small>{response.language}</small><b>{response.agent}</b></div>
                {/* Absent, not zero. A model that stated no confidence is not a
                    model that is certain it is wrong. */}
                <span className="lab-status">{response.status === 'responded' ? (response.confidence === null ? 'no confidence stated' : `confidence ${response.confidence}`) : 'unreachable'}</span>
            </header>
            <p>{response.answer?.trim() || 'This agent returned no text.'}</p>
            {response.dissent && <p className="lab-dissent"><b>Dissent</b>{response.dissent}</p>}
            {Array.isArray(response.evidence) && response.evidence.length > 0 && <details>
                <summary>{response.evidence.length} piece(s) of evidence this agent cited</summary>
                <pre>{JSON.stringify(response.evidence, null, 2)}</pre>
            </details>}
        </article>)}
    </div>;
}

/** The 0Learning this run filed. Recorded on disk and, until now, shown nowhere. */
function Learning({ learning, status }: { learning: RunLearning | null; status: string }) {
    if (!learning) {
        return ['reviewed', 'abandoned'].includes(status)
            ? <section className="lab-results-empty"><b>No learning</b><p>This run ended without filing a 0Learning. Every terminal consensus run is supposed to leave one behind, so this is itself worth looking into — check the log for a report from the recorder.</p></section>
            : null;
    }

    return <article className={`lab-learning is-${learning.severity}`}>
        <header><small>0Learning · {learning.severity_label}</small><h3>{learning.ref} — {learning.title}</h3></header>
        <dl>
            <dt>What was learned</dt><dd>{learning.what_was_learned}</dd>
            <dt>Evidence</dt><dd>{learning.evidence}</dd>
            <dt>Why it matters</dt><dd>{learning.why_it_matters}</dd>
            {learning.what_should_change && <><dt>What should change</dt><dd>{learning.what_should_change}</dd></>}
        </dl>
        <footer><code>{learning.path}</code></footer>
    </article>;
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) { return <label><span className="k-mono mb-2 block">{label}</span>{children}{error && <small className="text-[var(--k-mag)]">{error}</small>}</label>; }
