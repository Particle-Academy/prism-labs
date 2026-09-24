import { useState, type FormEvent } from 'react';
import { LabShell } from '../../components/lab-shell';
import { RunDiagnosticsView, type RunDiagnostics } from '../../components/run-diagnostics';
import { CacheEvidence, FetchResultView, type CacheResult, type FetchResult } from '../../components/provider-probe-results';

type ResearchResult = { ok: boolean; reason?: string; answer?: string; diagnostics?: RunDiagnostics };

async function postProbe<T>(path: string, data: unknown): Promise<T> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(data) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message ?? 'Probe could not run.');
    return body;
}

export default function ProviderProbes() {
    const [question, setQuestion] = useState('What changed in the topic I am researching?');
    const [research, setResearch] = useState<ResearchResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [url, setUrl] = useState('http://169.254.169.254/latest/meta-data/');
    const [fetched, setFetched] = useState<FetchResult | null>(null);
    const [fetchBusy, setFetchBusy] = useState(false);
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [model, setModel] = useState('claude-sonnet-5');
    const [prefix, setPrefix] = useState('');
    const [cacheQuestion, setCacheQuestion] = useState('Summarize the reference in one sentence.');
    const [followUp, setFollowUp] = useState('What is the most important detail in the reference?');
    const [cache, setCache] = useState<CacheResult | null>(null);
    const [cacheBusy, setCacheBusy] = useState(false);
    const [cacheError, setCacheError] = useState<string | null>(null);
    async function runResearch(event: FormEvent) {
        event.preventDefault(); setBusy(true); setError(null); setResearch(null);
        try { setResearch(await postProbe<ResearchResult>('/lab/provider-probes/research', { question })); }
        catch (reason) { setError(reason instanceof Error ? reason.message : 'Research failed.'); }
        finally { setBusy(false); }
    }
    async function runFetch(event: FormEvent) {
        event.preventDefault(); setFetchBusy(true); setFetchError(null); setFetched(null);
        try { setFetched(await postProbe<FetchResult>('/lab/provider-probes/fetch', { url })); }
        catch (reason) { setFetchError(reason instanceof Error ? reason.message : 'Fetch failed.'); }
        finally { setFetchBusy(false); }
    }
    async function runCache(event: FormEvent) {
        event.preventDefault(); setCacheBusy(true); setCacheError(null); setCache(null);
        try { setCache(await postProbe<CacheResult>('/lab/provider-probes/cache', { model, prefix, question: cacheQuestion, follow_up: followUp })); }
        catch (reason) { setCacheError(reason instanceof Error ? reason.message : 'Cache probe failed.'); }
        finally { setCacheBusy(false); }
    }
    return <LabShell title="Provider probes" current="/lab/provider-probes" eyebrow="Local diagnostic tools">
        <h1 className="lab-title">Provider probes</h1>
        <p className="lab-lead">Nothing runs on page load. Provider buttons make real, billable calls when you submit them.</p>
        <section className="k-card mt-6 space-y-4 p-6">
            <h2 className="text-xl font-bold">Perplexity research</h2>
            <form onSubmit={runResearch} className="space-y-3">
                <label className="block">Question<textarea className="lab-input mt-2 min-h-28" value={question} onChange={e => setQuestion(e.target.value)} required maxLength={10000} /></label>
                <button className="k-btn k-btn--grad" disabled={busy}>{busy ? 'Researching…' : 'Run research · paid call'}</button>
            </form>
            {error && <p role="alert">{error}</p>}
            {research && <div><p>{research.ok ? 'Completed' : 'Unsuccessful run'}</p>{research.reason && <p>{research.reason}</p>}{research.answer && <pre className="whitespace-pre-wrap">{research.answer}</pre>}<RunDiagnosticsView diagnostics={research.diagnostics} /></div>}
        </section>
        <section className="k-card mt-6 space-y-4 p-6">
            <h2 className="text-xl font-bold">Guarded public URL fetch</h2>
            <p>Private addresses, unsafe schemes, unresolved hosts and redirects into private networks should be refused. The preset metadata address must be refused before a request is sent.</p>
            <form onSubmit={runFetch} className="space-y-3">
                <label className="block">URL<input className="lab-input mt-2" value={url} onChange={e => setUrl(e.target.value)} required maxLength={2048} /></label>
                <button className="k-btn k-btn--grad" disabled={fetchBusy}>{fetchBusy ? 'Checking…' : 'Fetch through public URL guard'}</button>
            </form>
            {fetchError && <p role="alert">{fetchError}</p>}
            {fetched && <FetchResultView result={fetched} />}
        </section>
        <section className="k-card mt-6 space-y-4 p-6">
            <h2 className="text-xl font-bold">Cache stability hints · Anthropic</h2>
            <p>Send the same stable prefix twice with different volatile questions. Compare the provider’s cache read and write token counts. A short prefix may be below the model’s caching threshold; zero or missing counts do not prove reuse.</p>
            <form onSubmit={runCache} className="space-y-3">
                <label className="block">Model<input className="lab-input mt-2" value={model} onChange={e => setModel(e.target.value)} required maxLength={120} /></label>
                <label className="block">Stable reference<textarea className="lab-input mt-2 min-h-48" placeholder="Paste the reference that stays the same for both questions." value={prefix} onChange={e => setPrefix(e.target.value)} required maxLength={100000} /></label>
                <label className="block">First question<input className="lab-input mt-2" value={cacheQuestion} onChange={e => setCacheQuestion(e.target.value)} required maxLength={2000} /></label>
                <label className="block">Second question<input className="lab-input mt-2" value={followUp} onChange={e => setFollowUp(e.target.value)} required maxLength={2000} /></label>
                <button className="k-btn k-btn--grad" disabled={cacheBusy}>{cacheBusy ? 'Comparing two calls…' : 'Compare cache usage · 2 paid calls'}</button>
            </form>
            {cacheError && <p role="alert">{cacheError}</p>}
            {cache && <CacheEvidence result={cache} />}
        </section>
    </LabShell>;
}
