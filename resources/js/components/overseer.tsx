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
    const [recording, setRecording] = useState(false);
    const transcript = useRef<HTMLDivElement>(null);
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);

    useEffect(() => { void load(); }, []);
    useEffect(() => {
        const node = transcript.current;
        if (node) node.scrollTo({ top: node.scrollHeight, behavior: 'smooth' });
    }, [messages, sending]);

    // A recorder left running when the drawer closes is a HOT MICROPHONE with
    // no UI attached to it: the browser keeps showing its recording indicator
    // and the operator has nothing left to press to stop it.
    useEffect(() => () => releaseMicrophone(), []);

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

    function releaseMicrophone() {
        recorder.current?.stream.getTracks().forEach(track => track.stop());
        recorder.current = null;
    }

    /**
     * PRESS-TO-TALK, as a toggle rather than a held button.
     *
     * Click to start, click to stop — still one discrete utterance with an
     * explicit end, which is the property that separates this from an open
     * microphone. Hold-to-talk was the other option and was rejected twice
     * over: a held pointer released outside the window never fires its
     * `pointerup`, leaving the recorder running while the button looks idle;
     * and a button you must hold cannot be operated from a keyboard at all.
     */
    async function startRecording() {
        if (sending || recording) return;
        setError(null);

        // Absent, not denied. `mediaDevices` is undefined on an insecure
        // origin, which is a different problem with a different fix, and
        // "permission denied" would send someone to the wrong settings page.
        if (!navigator.mediaDevices?.getUserMedia) {
            setError('This browser will not expose a microphone here. Voice needs a secure origin (https).');
            return;
        }

        let stream: MediaStream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (reason) {
            const name = reason instanceof DOMException ? reason.name : '';
            setError(name === 'NotAllowedError'
                ? 'The microphone is blocked. Allow it for this site, then press Speak again.'
                : name === 'NotFoundError' ? 'No microphone was found.' : 'The microphone could not be opened.');
            return;
        }

        // The first type the browser actually supports, constrained to what the
        // endpoint accepts. Left to the default, Chrome hands back
        // `audio/webm;codecs=opus` and Safari `audio/mp4`; a container the
        // transcriber cannot read fails deep inside the provider rather than here.
        const type = ['audio/webm', 'audio/ogg', 'audio/mp4'].find(candidate => MediaRecorder.isTypeSupported(candidate));
        const instance = new MediaRecorder(stream, type ? { mimeType: type } : undefined);

        chunks.current = [];
        instance.ondataavailable = event => { if (event.data.size > 0) chunks.current.push(event.data); };
        instance.onstop = () => {
            const blob = new Blob(chunks.current, { type: instance.mimeType });
            releaseMicrophone();
            void sendUtterance(blob);
        };

        recorder.current = instance;
        instance.start();
        setRecording(true);
    }

    function stopRecording() {
        if (!recording) return;
        setRecording(false);
        recorder.current?.stop();
    }

    async function sendUtterance(blob: Blob) {
        if (blob.size === 0) { setError('Nothing was recorded.'); return; }

        setSending(true);
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        try {
            // `readAsDataURL` yields `data:audio/webm;codecs=opus;base64,…`; the
            // payload is what follows the comma. The type is sent WITHOUT its
            // codec parameter — the endpoint allowlists bare types, and the
            // transcriber wants the container rather than the codec.
            const encoded = await new Promise<string>((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result).split(',')[1] ?? '');
                reader.onerror = () => reject(new Error('The recording could not be read.'));
                reader.readAsDataURL(blob);
            });

            const response = await fetch('/lab/agent/voice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ audio: encoded, mime: (blob.type || 'audio/webm').split(';')[0] }),
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'The Overseer could not answer.');

            // NOTHING WAS HEARD. The harness deliberately does not spend a turn
            // on silence, so there is no turn to render — a bubble here would
            // put a message in the transcript that is not in the thread.
            if (body.empty) { setError('I did not catch that.'); return; }

            // What was HEARD goes in the transcript, always. A right answer to
            // a wrong transcription is a microphone problem, and showing only
            // the answer hides which of the two just happened.
            const spoken: Message[] = [{ id: `heard-${Date.now()}`, role: 'user', content: body.heard }];
            if (body.text) spoken.push({ id: `voice-${Date.now()}`, role: 'assistant', content: body.text });
            setMessages(current => [...current, ...spoken]);
            setDrafts(body.drafts ?? []);
            router.reload({ only: ['specs'] });

            // Null on a turn that only called tools, which is legitimate rather
            // than an error — the answer is already on screen as text.
            if (body.audio) await play(body.audio, body.audio_type);
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'The Overseer could not answer.'); }
        finally { setSending(false); }
    }

    async function play(base64: string, type: string | null) {
        try {
            await new window.Audio(`data:${type ?? 'audio/mpeg'};base64,${base64}`).play();
        } catch {
            // The only part of a turn that can fail AFTER the answer is already
            // visible, so it is reported quietly rather than as a failed turn.
            setError('The reply is above; it could not be played aloud.');
        }
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
            // POST returns a TICKET, not an answer. The turn runs on a queue
            // because it can call other agents and run whole suites, and this
            // site serves one request at a time — holding the worker for that
            // is what used to wedge the Lab until someone restarted it.
            const response = await fetch('/lab/agent', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ message: content }) });
            const accepted = await response.json();
            if (!response.ok) throw new Error(accepted.message ?? 'The Overseer could not answer.');
            if (accepted.drafts) setDrafts(accepted.drafts);

            const answered = await awaitTurn(accepted.turn_id);
            if (answered.error) throw new Error(answered.error);
            if (answered.message) setMessages(current => [...current, answered.message]);
            if (answered.drafts) setDrafts(answered.drafts);
            router.reload({ only: ['specs'] });
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'The Overseer could not answer.'); }
        finally { setSending(false); }
    }

    /**
     * Poll one turn until it is done.
     *
     * Backs off from 1s to 5s. A fixed fast interval would be the original
     * problem wearing a different hat: on a single-threaded server every poll
     * competes with the queue worker's own database access, and a turn that
     * legitimately takes four minutes does not need 240 checks to notice.
     *
     * The ceiling is generous and is NOT the turn's timeout — the job owns
     * that, and marks the row failed when it runs out. This gives up on
     * WATCHING, and says so honestly: the answer may still land in the thread,
     * which a reload will show.
     */
    async function awaitTurn(turnId: string) {
        const deadline = Date.now() + 20 * 60 * 1000;
        let wait = 1000;

        while (Date.now() < deadline) {
            await new Promise(resolve => setTimeout(resolve, wait));
            wait = Math.min(wait * 1.5, 5000);

            const response = await fetch(`/lab/agent/turn/${turnId}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) continue;

            const body = await response.json();
            if (!body.pending) return body;
        }

        throw new Error('The Overseer is still working and this page stopped watching. Reload to see the answer when it lands.');
    }

    return <section className={`plab-chat ${compact ? 'is-compact' : ''}`}><div className="plab-transcript" ref={transcript}>{loading && <AgentThinking label="Loading your conversation…" />}{!loading && messages.length === 0 && <Welcome />}{messages.map(message => <article key={message.id} className={`plab-message is-${message.role}`}>{message.role === 'assistant' && <span className="plab-message-avatar">P</span>}<div><small>{message.role === 'assistant' ? 'Overseer' : 'You'}</small>{message.role === 'assistant' ? <ContentRenderer value={message.content} format="markdown" /> : <p>{message.content}</p>}</div></article>)}{sending && <AgentThinking label="PLab is thinking through the test…" />}{recording && <div className="overseer-recording" role="status"><span />Listening — press Stop and send when you are done.</div>}{error && <div className="overseer-error" role="alert">{error}</div>}</div>{drafts.length > 0 && <div className="plab-drafts"><span>Proposed specifications</span>{drafts.slice(0, 3).map(draft => <Link key={draft.id} href={`/lab/benchmarks/specs/${draft.id}`}><b>{draft.name}</b><small>Revision {draft.revision} · {draft.status} · Review spec →</small></Link>)}</div>}<div className="plab-composer"><button type="button" className={`overseer-mic ${recording ? 'is-recording' : ''}`} disabled={sending} aria-pressed={recording} aria-label={recording ? 'Stop recording and send' : 'Record a spoken message'} onClick={() => (recording ? stopRecording() : void startRecording())}><span />{recording ? 'Stop and send' : 'Speak'}</button><PromptInput budgetTokens={12_000} commands={[{ name: '/clear', hint: 'Start a new conversation — the old one is kept, not deleted' }, { name: '/benchmark', hint: 'Design a benchmark together' }, { name: '/compare', hint: 'Plan a parity comparison' }, { name: '/research', hint: 'Research before writing the test' }]} mentions={[{ id: 'php', name: 'PHP lane', kind: 'agent' }, { id: 'typescript', name: 'TypeScript lane', kind: 'agent' }, { id: 'python', name: 'Python lane', kind: 'agent' }]} onSubmit={(text, attachments) => void submit(text, attachments)} placeholder="Tell PLab what you want to learn or test…" maxHeight={160} /></div></section>;
}

function AgentIdentity() { return <div className="overseer-identity"><span className="overseer-mark">P</span><div><b>Overseer</b><small>Coordinator · overseer · durable memory</small></div><i /></div>; }
function AgentThinking({ label }: { label: string }) { return <div className="plab-thinking"><span /><span /><span /><small>{label}</small></div>; }
function Welcome() { return <div className="plab-welcome"><span className="overseer-mark">P</span><h2>What should we learn?</h2><p>Describe the question, product, behavior, or artifact you want to evaluate. I’ll help shape the contract, decide what evidence counts, design the rubric and budgets, and create a draft only when it is ready for your review.</p><div><span>Cross-language benchmarks</span><span>Human+ workflows</span><span>Cost and correction rates</span></div></div>; }
