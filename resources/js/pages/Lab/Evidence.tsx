import { router } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type Learning = {
    ref: string; title: string; severity: string; severity_label: string; filed_by: string;
    what_was_learned: string; evidence: string; why_it_matters: string; what_should_change: string | null;
    path: string; sent_at: string | null; acted_at: string | null; acted_note: string | null;
};

export default function Evidence({ open, closed }: { open: Learning[]; closed: Learning[] }) {
    const [sending, setSending] = useState(false);

    // HUMAN-TRIGGERED. Nothing sends on a schedule or when a run finishes —
    // the operator decides a batch is worth an agent's attention, and delivery
    // from that press onward is automatic.
    const send = () => {
        setSending(true);
        router.post('/lab/evidence/send', {}, { preserveScroll: true, onFinish: () => setSending(false) });
    };

    return <LabShell title="Evidence" current="/lab/evidence" eyebrow="0Learning registry">
        <div className="lab-page-heading">
            <div>
                <h1 className="lab-title">What the Lab has learned.</h1>
                <p className="lab-lead">Every terminal run files a 0Learning — including the ones that produced nothing, which are the most worth reading. A learning stays open until somebody records what was done about it.</p>
            </div>
            <div className="lab-run-actions">
                <button type="button" className="k-btn k-btn--primary" onClick={send} disabled={sending || open.length === 0}>
                    {sending ? 'Sending…' : open.length === 0 ? 'Nothing open' : `Send ${open.length} to agent`}
                </button>
            </div>
        </div>

        <section className="lab-panel">
            <div className="lab-panel-head"><span>Open</span><span>{open.length} waiting on someone</span></div>
            {open.length === 0
                ? <p className="lab-empty">Nothing open. Every filed learning has been acted on.</p>
                : open.map(learning => <LearningRow key={learning.ref} learning={learning} />)}
        </section>

        {closed.length > 0 && <section className="lab-panel" style={{ marginTop: '.85rem' }}>
            <div className="lab-panel-head"><span>Acted on</span><span>{closed.length} most recent</span></div>
            {closed.map(learning => <div key={learning.ref} className="lab-learning-closed">
                <b>{learning.ref}</b>
                <span>{learning.title}</span>
                {/* The note is the point. "Closed" with no reason is the same as
                    deleted — it loses whether this was fixed, deferred on
                    purpose, or judged wrong. */}
                <p>{learning.acted_note}</p>
            </div>)}
        </section>}
    </LabShell>;
}

function LearningRow({ learning }: { learning: Learning }) {
    const [note, setNote] = useState('');

    const close = () => {
        if (note.trim() === '') return;
        router.post('/lab/evidence/close', { ref: learning.ref, note }, { preserveScroll: true });
    };

    return <article className={`lab-learning is-${learning.severity}`}>
        <header>
            <div>
                <small>{learning.severity_label} · filed by {learning.filed_by}{learning.sent_at ? ' · already sent to an agent' : ''}</small>
                <h3>{learning.ref} — {learning.title}</h3>
            </div>
        </header>
        <dl>
            <dt>What was learned</dt><dd>{learning.what_was_learned}</dd>
            <dt>Evidence</dt><dd>{learning.evidence}</dd>
            <dt>Why it matters</dt><dd>{learning.why_it_matters}</dd>
            {learning.what_should_change && <><dt>What should change</dt><dd>{learning.what_should_change}</dd></>}
        </dl>
        <footer>
            <code>{learning.path}</code>
            <div className="lab-close-learning">
                <input
                    type="text"
                    value={note}
                    placeholder="What was done about it? A deliberate deferral counts."
                    onChange={event => setNote(event.target.value)}
                />
                <button type="button" className="k-btn k-btn--ghost k-btn--small" onClick={close} disabled={note.trim() === ''}>Close</button>
            </div>
        </footer>
    </article>;
}
