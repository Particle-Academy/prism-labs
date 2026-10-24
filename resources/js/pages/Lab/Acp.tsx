import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Update = Record<string, unknown> & { sessionUpdate: string };
type Provider = 'claude' | 'codex';
const providerNames: Record<Provider, string> = { claude: 'Claude', codex: 'Codex' };
type Turn = {
    id: string;
    status: 'queued' | 'running' | 'finished' | 'failed';
    pending: boolean;
    prompt: string;
    provider: Provider;
    model: string | null;
    resume_session_id: string | null;
    cli_session_id: string | null;
    stop_reason: string | null;
    error: string | null;
    updates: Update[];
    history_updates: Update[];
    handshake: Record<string, unknown> | null;
    process: { tree?: { host_pid: number; supervisor_pid: number; containment: string }; stop?: Record<string, unknown>; stderr?: string } | null;
    created_at: string | null;
};

// What the agent SAID: its message chunks, joined. Thoughts and tool calls are shown apart, because a reader
// skimming the answer should not have to separate them.
function said(updates: Update[], kind: 'agent_message_chunk' | 'agent_thought_chunk') {
    return updates
        .filter((u) => u.sessionUpdate === kind)
        .map((u) => ((u.content as { text?: string } | undefined)?.text ?? ''))
        .join('');
}

function counts(updates: Update[]) {
    const out: Record<string, number> = {};
    for (const u of updates) out[u.sessionUpdate] = (out[u.sessionUpdate] ?? 0) + 1;
    return Object.entries(out);
}

function TurnCard({ turn, onContinue }: { turn: Turn; onContinue: (turn: Turn) => void }) {
    const answer = said(turn.updates, 'agent_message_chunk');
    const thought = said(turn.updates, 'agent_thought_chunk');
    const tools = turn.updates.filter((u) => u.sessionUpdate === 'tool_call');
    const notices = turn.updates.filter((u) => u.sessionUpdate === 'notice');
    const usage = [...turn.updates].reverse().find((u) => u.sessionUpdate === 'usage_update');
    const agent = (turn.handshake?.initialize as { agentInfo?: { title?: string; name?: string } } | undefined)?.agentInfo;
    const stop = turn.process?.stop;
    return (
        <section className="k-card p-6">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
                <p className="k-mono uppercase">
                    {providerNames[turn.provider ?? 'claude']} · {' '}
                    {turn.status}
                    {turn.stop_reason ? ` · ${turn.stop_reason}` : ''}
                    {turn.resume_session_id ? ' · continued (session/load)' : ''}
                </p>
                <p className="k-mono text-xs text-[var(--k-ink-2)]">{turn.model ?? 'default model'} · {turn.created_at?.replace('T', ' ').slice(0, 19)}</p>
            </div>
            <p className="mt-3 whitespace-pre-wrap font-semibold">{turn.prompt}</p>
            {answer ? <p className="mt-4 whitespace-pre-wrap" data-testid="acp-answer">{answer}</p> : turn.pending ? <p className="mt-4 text-[var(--k-ink-2)]">{turn.status === 'queued' ? 'Waiting for the Overseer worker…' : 'Waiting for the agent…'}</p> : null}
            {(turn.history_updates?.length ?? 0) > 0 ? <details className="mt-3"><summary className="k-mono cursor-pointer text-sm">Replayed conversation history</summary><pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap text-sm">{JSON.stringify(turn.history_updates, null, 1)}</pre></details> : null}
            {thought ? <details className="mt-3"><summary className="k-mono cursor-pointer text-sm">thinking ({thought.length} chars)</summary><p className="mt-2 whitespace-pre-wrap text-sm text-[var(--k-ink-2)]">{thought}</p></details> : null}
            {tools.length > 0 ? <ul className="k-mono mt-3 text-sm">{tools.map((t) => <li key={String(t.toolCallId)}>tool · {String(t.title)}{t.kind ? ` (${String(t.kind)})` : ''}</li>)}</ul> : null}
            {notices.length > 0 ? <ul className="mt-3 text-sm text-[var(--k-ink-2)]">{notices.map((n, i) => <li key={i}>{String((n.notice as { message?: string } | undefined)?.message ?? '')}</li>)}</ul> : null}
            {turn.error ? <p className="mt-3 text-sm text-red-500">{turn.error}</p> : null}
            <div className="k-mono mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--k-ink-2)]">
                {agent ? <span>agent: {agent.title ?? agent.name}</span> : null}
                {usage ? <span>context: {Number(usage.used).toLocaleString()} / {Number(usage.size).toLocaleString()}</span> : null}
                {turn.cli_session_id ? <span>CLI session: {turn.cli_session_id}</span> : null}
                {turn.process?.tree ? <span>host pid {turn.process.tree.host_pid} · {turn.process.tree.containment}</span> : null}
                {stop ? <span>stop: {stop.empty === true ? 'tree empty' : `${String(stop.survivors)} left`}{stop.verified === true ? ', verified' : ', unverified'}{stop.graceful === true ? ', graceful' : ''}</span> : null}
                <span>updates: {counts(turn.updates).map(([k, n]) => `${k}×${n}`).join(', ') || '—'}</span>
            </div>
            <details className="mt-3"><summary className="k-mono cursor-pointer text-xs">raw session/update stream</summary><pre className="mt-2 max-h-96 overflow-auto text-xs">{JSON.stringify(turn.updates, null, 1)}</pre></details>
            {turn.process?.stderr ? <details className="mt-1"><summary className="k-mono cursor-pointer text-xs">agent stderr</summary><pre className="mt-2 max-h-64 overflow-auto text-xs">{turn.process.stderr}</pre></details> : null}
            {!turn.pending && turn.cli_session_id ? <button type="button" className="k-btn mt-4" onClick={() => onContinue(turn)}>Continue this conversation</button> : null}
        </section>
    );
}

export default function Acp({ version, turns: initial, model: defaultModel, models, acpVersion }: { version: string; turns: Turn[]; model: string; models: Record<Provider, string>; acpVersion: string }) {
    const [turns, setTurns] = useState<Turn[]>(initial);
    const [prompt, setPrompt] = useState('');
    const [model, setModel] = useState(defaultModel);
    const [provider, setProvider] = useState<Provider>('claude');
    const [continuing, setContinuing] = useState<Turn | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const turnsRef = useRef(turns);
    turnsRef.current = turns;

    function chooseProvider(next: Provider) {
        setProvider(next);
        setModel(models[next]);
        setContinuing(null);
    }

    function continueTurn(turn: Turn) {
        const next = turn.provider ?? 'claude';
        setProvider(next);
        setModel(turn.model ?? models[next]);
        setContinuing(turn);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Poll the turns still running; a finished turn is not asked about again.
    useEffect(() => {
        const timer = setInterval(async () => {
            const pending = turnsRef.current.filter((t) => t.pending);
            for (const turn of pending) {
                const response = await fetch(`/lab/acp/turn/${turn.id}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) continue;
                const fresh = (await response.json()) as Turn;
                setTurns((all) => all.map((t) => (t.id === fresh.id ? fresh : t)));
            }
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    async function send() {
        setError(null);
        setBusy(true);
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch('/lab/acp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ prompt, provider, model: model || null, continue: continuing?.id ?? null }),
            });
            const body = await response.json();
            if (!response.ok) {
                setError(body.error ?? body.message ?? `HTTP ${response.status}`);
                return;
            }
            setTurns((all) => [body as Turn, ...all]);
            setPrompt('');
            setContinuing(null);
        } catch (problem) {
            setError(problem instanceof Error ? problem.message : 'The turn could not be queued.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="k-page">
            <Head title="Prism Lab ACP agent" />
            <KineticNav version={version} />
            <main className="mx-auto max-w-5xl px-6 py-16">
                <LabNav current="/lab/acp" />
                <p className="k-mono mb-4">particle-academy/prism-acp {acpVersion} · PHP · Claude / Codex</p>
                <h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">ACP <span className="k-grad-text">agent</span></h1>
                <p className="mt-5 max-w-3xl text-lg text-[var(--k-ink-2)]">{labBlurb('/lab/acp')}</p>
                <section className="k-card mt-10 p-6">
                    {continuing ? (
                        <p className="k-mono mb-3 text-sm">
                            Continuing {providerNames[provider]} {continuing.cli_session_id} (session/load) · <button type="button" className="underline" onClick={() => setContinuing(null)}>start fresh instead</button>
                        </p>
                    ) : null}
                    <textarea aria-label="Prompt" className="lab-input min-h-28 w-full resize-y" value={prompt} onChange={(e) => setPrompt(e.target.value)} placeholder="Ask the agent something." />
                    <p className="mt-2 text-sm text-[var(--k-ink-2)]">{provider === 'claude' ? 'Claude: every tool call is denied.' : 'Codex: read-only sandbox; shell, search, apps and subagents disabled; MCP servers disabled. Approval requests are denied.'} Uses your native CLI login.</p>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <label className="k-mono text-sm" htmlFor="acp-provider">Provider</label>
                        <select id="acp-provider" className="lab-input" value={provider} onChange={(e) => chooseProvider(e.target.value as Provider)}><option value="claude">Claude</option><option value="codex">Codex</option></select>
                        <label className="k-mono text-sm" htmlFor="acp-model">Model</label>
                        <input id="acp-model" className="lab-input w-48" placeholder={provider === 'codex' ? 'native default' : ''} value={model} onChange={(e) => setModel(e.target.value)} />
                        <button type="button" className="k-btn k-btn--grad" disabled={busy || prompt.trim() === ''} onClick={send}>Run turn</button>
                        {error ? <span className="text-sm text-red-500">{error}</span> : null}
                    </div>
                </section>
                <div className="mt-8 grid gap-4">
                    {turns.map((turn) => <TurnCard key={turn.id} turn={turn} onContinue={continueTurn} />)}
                </div>
            </main>
            <KineticFooter />
        </div>
    );
}
