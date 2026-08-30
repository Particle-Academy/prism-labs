import { ContentRenderer } from '@particle-academy/react-fancy';
import { FormEvent, useMemo, useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type ProviderInfo = {
    key: string;
    label: string;
    env: string | null;
    model: string | null;
    modality: 'text' | 'audio' | 'embeddings';
    requiresKey: boolean;
    configured: boolean;
    described: boolean;
};
type Metrics = { latency_ms: number; prompt_tokens: number; completion_tokens: number; provider_reported_cost: number | null; cost_source: string; steps: number; tool_calls: number; finish_reason: string };
type Capabilities = { mode: 'none' | 'browser' | 'human_plus' | 'both'; browser: Record<string, unknown> | null; human_plus: Record<string, unknown> | null };

export default function Chat({ version, providers, undescribed, phoenixUrl, capabilities: initialCapabilities }: { version: string; providers: ProviderInfo[]; undescribed: string[]; phoenixUrl: string; capabilities: Capabilities }) {
    // Only text providers can drive this form; audio/embeddings ones are
    // reported in the status panel instead of being offered here.
    const textProviders = useMemo(() => providers.filter(p => p.modality === 'text'), [providers]);
    // Anthropic Sonnet is the default because it is the one this Lab is used to
    // judge others against — starting on whichever provider happens to sit
    // first in config made the baseline depend on config order.
    const initial = useMemo(
        () =>
            textProviders.find(p => p.key === 'anthropic' && p.configured)
            ?? textProviders.find(p => p.configured)
            ?? textProviders[0],
        [textProviders],
    );

    const [provider, setProvider] = useState<string>(initial?.key ?? '');
    const [model, setModel] = useState(initial?.model ?? '');
    const [feature, setFeature] = useState<'text' | 'tools' | 'research'>('tools');
    const [prompt, setPrompt] = useState('Inspect the Prism runtime, then explain whether telemetry is ready for a real generation.');
    const [result, setResult] = useState<{ text: string; metrics: Metrics; phoenix_url: string } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);
    const [capabilities, setCapabilities] = useState(initialCapabilities);
    const [capabilityBusy, setCapabilityBusy] = useState(false);
    const [invitation, setInvitation] = useState({ relay_url: '', session_id: '', token: '', surface_id: '', application: '' });

    const current = useMemo(() => textProviders.find(p => p.key === provider), [textProviders, provider]);
    const readyCount = textProviders.filter(p => p.configured).length;

    const keyWarning = useMemo(() => {
        if (!current || current.configured) return null;
        if (current.env) return `Set ${current.env} in repos/prism-sandbox/.env, then reload to use ${current.label}.`;
        if (current.key === 'ollama') return 'Start Ollama locally, or set OLLAMA_URL in the sandbox .env.';
        return `Configure prism.providers.${current.key} in the sandbox .env, then reload.`;
    }, [current]);

    function chooseProvider(next: string) {
        setProvider(next);
        setModel(textProviders.find(p => p.key === next)?.model ?? '');
        setResult(null);
        setError(null);
    }

    async function submit(event: FormEvent) {
        event.preventDefault(); setRunning(true); setError(null); setResult(null);
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        try {
            const response = await fetch('/lab/chat', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ provider, model, feature, prompt }) });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'Generation failed.');
            setResult(body);
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'Generation failed.'); }
        finally { setRunning(false); }
    }

    async function capabilityRequest(path: string, method: 'POST' | 'DELETE', body?: object) {
        setCapabilityBusy(true); setError(null);
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        try {
            const response = await fetch(path, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message ?? 'Capability operation failed.');
            const status = await fetch('/lab/capabilities', { headers: { Accept: 'application/json' } });
            setCapabilities(await status.json());
            if (path.includes('human-plus') && method === 'POST') setInvitation(value => ({ ...value, token: '' }));
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'Capability operation failed.'); }
        finally { setCapabilityBusy(false); }
    }

    void version;
    return <LabShell title="Harness Agent" current="/lab/chat" eyebrow="Prism.php · durable Harness session"><div className="lab-page-heading"><div><h1 className="lab-title">Agent workspace</h1><p className="lab-lead">Operate the Lab through one durable agent with parity, research, Browser, and Human+ capabilities.</p></div><a className="k-btn k-btn--ghost" href={phoenixUrl} target="_blank" rel="noreferrer">Open trace diagnostics ↗</a></div>
        <section className="k-card mt-10 p-6 sm:p-8"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="k-mono">Harness capabilities · {capabilities.mode}</p><h2 className="mt-2 text-2xl font-bold">Browser and Human+</h2></div><div className="flex gap-2">{capabilities.browser ? <button className="k-btn k-btn--ghost" disabled={capabilityBusy} onClick={() => capabilityRequest('/lab/capabilities/browser', 'DELETE')}>Close browser</button> : <button className="k-btn k-btn--grad" disabled={capabilityBusy} onClick={() => capabilityRequest('/lab/capabilities/browser', 'POST')}>Open headless browser</button>}</div></div><p className="mt-3 text-[var(--k-ink-2)]">Browser runs a guarded headless Chromium service. Human+ joins an active Fancy surface through its stable JSON/MCP bridge; a human may or may not be present.</p><div className="mt-6 grid gap-4 lg:grid-cols-2"><pre className="overflow-auto rounded-xl bg-[var(--k-bg-2)] p-4 text-xs">{JSON.stringify(capabilities.browser ?? { state: 'detached' }, null, 2)}</pre><div>{capabilities.human_plus ? <div><pre className="overflow-auto rounded-xl bg-[var(--k-bg-2)] p-4 text-xs">{JSON.stringify(capabilities.human_plus, null, 2)}</pre><button className="k-btn k-btn--ghost mt-3" disabled={capabilityBusy} onClick={() => capabilityRequest('/lab/capabilities/human-plus', 'DELETE')}>Leave Human+ session</button></div> : <div className="grid gap-3 sm:grid-cols-2">{Object.entries(invitation).map(([key, value]) => <Field key={key} label={key.replaceAll('_', ' ')}><input type={key === 'token' ? 'password' : 'text'} className="lab-input" value={value} autoComplete="off" onChange={event => setInvitation(current => ({ ...current, [key]: event.target.value }))} /></Field>)}<button className="k-btn k-btn--grad sm:col-span-2" disabled={capabilityBusy} onClick={() => capabilityRequest('/lab/capabilities/human-plus', 'POST', invitation)}>Join Human+ session</button></div>}</div></div></section>
        <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(340px,.85fr)]"><form onSubmit={submit} className="k-card space-y-6 p-6 sm:p-8"><div className="grid gap-4 sm:grid-cols-3"><Field label={`Provider · ${readyCount}/${textProviders.length} ready`}><select value={provider} onChange={e => chooseProvider(e.target.value)} className="lab-input">{textProviders.map(p => <option key={p.key} value={p.key}>{p.label}{p.configured ? '' : ' — not configured'}</option>)}</select></Field><Field label="Model"><input className="lab-input" value={model} onChange={e => setModel(e.target.value)} /></Field><Field label="Feature"><select className="lab-input" value={feature} onChange={e => setFeature(e.target.value as 'text' | 'tools' | 'research')}><option value="tools">Tools · multi-step</option><option value="research">Research · Perplexity search</option><option value="text">Text</option></select></Field></div><Field label="Prompt"><textarea className="lab-input min-h-48 resize-y" value={prompt} onChange={e => setPrompt(e.target.value)} /></Field>{keyWarning && <Notice>{keyWarning}</Notice>}<button className="k-btn k-btn--grad disabled:cursor-wait disabled:opacity-60" disabled={running}>{running ? 'Running generation…' : 'Run generation →'}</button></form>
        <section className="k-card min-h-96 p-6 sm:p-8"><p className="k-mono mb-5">Agent response</p>{error && <Notice>{error}</Notice>}{!result && !error && <p className="text-[var(--k-ink-3)]">The agent response, run state, token usage, tool activity, and cost appear here.</p>}{result && <div className="space-y-7"><ContentRenderer value={result.text} format="markdown" className="leading-7 text-[var(--k-ink)]" /><div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-[var(--k-hairline)] sm:grid-cols-3">{Object.entries(result.metrics).map(([key, value]) => <div key={key} className="bg-[var(--k-bg-1)] p-4"><div className="k-mono">{key.replaceAll('_', ' ')}</div><div className="mt-1 font-semibold tabular-nums">{value ?? '—'}</div></div>)}</div><a className="k-btn k-btn--ghost" href={result.phoenix_url} target="_blank" rel="noreferrer">Inspect trace in Phoenix ↗</a></div>}</section></div></LabShell>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="block"><span className="k-mono mb-2 block">{label}</span>{children}</label>; }
function Notice({ children }: { children: React.ReactNode }) { return <div className="rounded-xl border border-[var(--k-mag)]/40 bg-[rgba(255,46,136,.08)] p-4 text-sm text-[var(--k-ink-2)]">{children}</div>; }
