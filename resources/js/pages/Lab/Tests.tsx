import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type TestCase = { id: string; provider: 'openai' | 'anthropic'; model: string; feature: string; label: string; costly: boolean };
type Result = { id: string; provider: string; feature: string; passed: boolean; latency_ms: number; metrics: Record<string, unknown>; error: string | null; phoenix_url: string };

export default function Tests({ version, cases, availability, phoenixUrl }: { version: string; cases: TestCase[]; availability: Record<string, boolean>; phoenixUrl: string }) {
    const defaults = cases.filter(test => !test.costly && availability[test.provider]).map(test => test.id);
    const [selected, setSelected] = useState(defaults);
    const [results, setResults] = useState<Result[]>([]);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState<string | null>(null);
    function toggle(id: string) { setSelected(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id]); }
    async function run() {
        setRunning(true); setError(null); setResults([]);
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch('/lab/tests', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ cases: selected }) });
            const body = await response.json(); if (!response.ok) throw new Error(body.message ?? 'Suite failed.'); setResults(body.results);
        } catch (reason) { setError(reason instanceof Error ? reason.message : 'Suite failed.'); } finally { setRunning(false); }
    }
    return <div className="k-page"><Head title="Prism Lab Test Suite" /><KineticNav version={version} /><main className="mx-auto max-w-7xl px-6 py-16">
        <LabNav current="/lab/tests" /><div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end"><div><p className="k-mono mb-4">Local only · real generations</p><h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">Provider <span className="k-grad-text">test suite</span></h1><p className="mt-5 max-w-3xl text-lg text-[var(--k-ink-2)]">{labBlurb('/lab/tests')}</p></div><a className="k-btn k-btn--ghost" href={phoenixUrl} target="_blank" rel="noreferrer">Open Phoenix ↗</a></div>
        <section className="k-card mt-12 p-6 sm:p-8"><div className="grid gap-3 md:grid-cols-2">{cases.map(test => <label key={test.id} className="flex cursor-pointer items-center gap-4 rounded-xl border border-[var(--k-hairline)] p-4"><input type="checkbox" checked={selected.includes(test.id)} disabled={!availability[test.provider]} onChange={() => toggle(test.id)} /><span className="flex-1"><strong>{test.label}</strong><span className="k-mono mt-1 block">{test.model}{test.costly ? ' · costly' : ''}</span></span><span className={availability[test.provider] ? 'text-[var(--k-cyan)]' : 'text-[var(--k-mag)]'}>{availability[test.provider] ? 'ready' : 'no key'}</span></label>)}</div><button onClick={run} disabled={running || selected.length === 0} className="k-btn k-btn--grad mt-6 disabled:opacity-50">{running ? 'Running real generations…' : `Run ${selected.length} checks →`}</button>{error && <p className="mt-4 text-[var(--k-mag)]">{error}</p>}</section>
        {results.length > 0 && <section className="mt-6 grid gap-4">{results.map(result => <article key={result.id} className="k-card p-5"><div className="flex flex-wrap items-center justify-between gap-3"><div><span className={result.passed ? 'text-[var(--k-cyan)]' : 'text-[var(--k-mag)]'}>{result.passed ? 'PASS' : 'FAIL'}</span><h2 className="mt-1 text-xl font-bold">{result.id}</h2></div><span className="k-mono">{result.latency_ms} ms</span></div>{result.error && <p className="mt-3 text-[var(--k-mag)]">{result.error}</p>}<pre className="mt-4 overflow-auto text-xs text-[var(--k-ink-2)]">{JSON.stringify(result.metrics, null, 2)}</pre><a href={result.phoenix_url} target="_blank" rel="noreferrer" className="k-mono mt-3 inline-block">Inspect Phoenix ↗</a></article>)}</section>}
    </main><KineticFooter /></div>;
}
