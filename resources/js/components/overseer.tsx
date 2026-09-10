import { Link, router } from '@inertiajs/react';
import { ContentRenderer, Drawer, PromptInput, type PromptAttachment } from '@particle-academy/react-fancy';
import { useEffect, useRef, useState } from 'react';

type Message = { id: string; role: 'user' | 'assistant'; content: string };
type Draft = { id: string; name: string; revision: number; status: string; digest: string; archetype: string; surface_mode: string };

export function OverseerLauncher() {
    const [open, setOpen] = useState(false);
    useEffect(() => {
        const show = () => setOpen(true);
        window.addEventListener('overseer:open', show);
        return () => window.removeEventListener('overseer:open', show);
    }, []);
    return <><button className="overseer-launcher" type="button" onClick={() => setOpen(true)} aria-label="Open Overseer"><span className="overseer-mark">P</span><span><b>Overseer</b><small>Plan, research, and oversee</small></span><i /></button><Drawer open={open} onClose={() => setOpen(false)} side="right" size="xl" className="overseer-drawer"><Drawer.Header><AgentIdentity /></Drawer.Header><Drawer.Body className="p-0"><OverseerChat compact /></Drawer.Body></Drawer></>;
}

export function OverseerChat({ compact = false }: { compact?: boolean }) {
    const [messages, setMessages] = useState<Message[]>([]);
    const [drafts, setDrafts] = useState<Draft[]>([]);
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const transcript = useRef<HTMLDivElement>(null);

    useEffect(() => { void load(); }, []);
    useEffect(() => {
        const node = transcript.current;
        if (node) node.scrollTo({ top: node.scrollHeight, behavior: 'smooth' });
    }, [messages, sending]);

    async function load() {
        try {
            const response = await fetch('/lab/agent', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('The conversation could not be loaded.');
            const body = await response.json(); setMessages(body.messages ?? []); setDrafts(body.drafts ?? []);
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'The conversation could not be loaded.'); }
        finally { setLoading(false); }
    }

    async function clearConversation() {
        // Handled BEFORE the optimistic user bubble is added, so a cleared
        // transcript does not briefly show the command that cleared it.
        setSending(true); setError(null);
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        try {
            const response = await fetch('/lab/agent/clear', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'The conversation could not be cleared.');
            setMessages([]); setDrafts(body.drafts ?? []);
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'The conversation could not be cleared.'); }
        finally { setSending(false); }
    }

    async function submit(text: string, attachments: PromptAttachment[]) {
        if (sending) return;

        // A command, not a message. Sending "/clear" to the model would put it
        // in the thread being cleared and spend a turn on it.
        if (text.trim() === '/clear') { void clearConversation(); return; }

        setSending(true); setError(null);
        const attachmentText = await Promise.all(attachments.map(async attachment => attachment.file && attachment.file.size <= 200_000 ? `\n\nAttached file: ${attachment.name}\n\n${await attachment.file.text()}` : `\n\nAttached file: ${attachment.name} (not inlined because it exceeds 200 KB)`));
        const content = text + attachmentText.join('');
        const optimistic = { id: `local-${Date.now()}`, role: 'user' as const, content: text || `Attached ${attachments.map(item => item.name).join(', ')}` };
        setMessages(current => [...current, optimistic]);
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        try {
            const response = await fetch('/lab/agent', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ message: content }) });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'The Overseer could not answer.');
            setMessages(current => [...current, body.message]); setDrafts(body.drafts ?? []);
            router.reload({ only: ['specs'] });
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'The Overseer could not answer.'); }
        finally { setSending(false); }
    }

    return <section className={`plab-chat ${compact ? 'is-compact' : ''}`}><div className="plab-transcript" ref={transcript}>{loading && <AgentThinking label="Loading your conversation…" />}{!loading && messages.length === 0 && <Welcome />}{messages.map(message => <article key={message.id} className={`plab-message is-${message.role}`}>{message.role === 'assistant' && <span className="plab-message-avatar">P</span>}<div><small>{message.role === 'assistant' ? 'Overseer' : 'You'}</small>{message.role === 'assistant' ? <ContentRenderer value={message.content} format="markdown" /> : <p>{message.content}</p>}</div></article>)}{sending && <AgentThinking label="PLab is thinking through the test…" />}{error && <div className="overseer-error" role="alert">{error}</div>}</div>{drafts.length > 0 && <div className="plab-drafts"><span>Proposed specifications</span>{drafts.slice(0, 3).map(draft => <Link key={draft.id} href={`/lab/benchmarks/specs/${draft.id}`}><b>{draft.name}</b><small>Revision {draft.revision} · {draft.status} · Review spec →</small></Link>)}</div>}<div className="plab-composer"><PromptInput budgetTokens={12_000} commands={[{ name: '/clear', hint: 'Start a new conversation — the old one is kept, not deleted' }, { name: '/benchmark', hint: 'Design a benchmark together' }, { name: '/compare', hint: 'Plan a parity comparison' }, { name: '/research', hint: 'Research before writing the test' }]} mentions={[{ id: 'php', name: 'PHP lane', kind: 'agent' }, { id: 'typescript', name: 'TypeScript lane', kind: 'agent' }, { id: 'python', name: 'Python lane', kind: 'agent' }]} onSubmit={(text, attachments) => void submit(text, attachments)} placeholder="Tell PLab what you want to learn or test…" maxHeight={160} /></div></section>;
}

function AgentIdentity() { return <div className="overseer-identity"><span className="overseer-mark">P</span><div><b>Overseer</b><small>Coordinator · overseer · durable memory</small></div><i /></div>; }
function AgentThinking({ label }: { label: string }) { return <div className="plab-thinking"><span /><span /><span /><small>{label}</small></div>; }
function Welcome() { return <div className="plab-welcome"><span className="overseer-mark">P</span><h2>What should we learn?</h2><p>Describe the question, product, behavior, or artifact you want to evaluate. I’ll help shape the contract, decide what evidence counts, design the rubric and budgets, and create a draft only when it is ready for your review.</p><div><span>Cross-language benchmarks</span><span>Human+ workflows</span><span>Cost and correction rates</span></div></div>; }
