import { Head } from '@inertiajs/react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Benchmark = {
    provider: string;
    model: string;
    feature: string;
    runs: number;
    passed: number;
    pass_rate: number;
    avg_latency_ms: number | null;
    p95_latency_ms: number | null;
    avg_prompt_tokens: number | null;
    avg_completion_tokens: number | null;
    total_cost: number | null;
    last_run: string | null;
};

const show = (value: number | null, suffix = '') => (value === null ? '—' : `${value}${suffix}`);

export default function Benchmarks({ version, benchmarks, totalRuns, phoenixUrl }: { version: string; benchmarks: Benchmark[]; totalRuns: number; phoenixUrl: string }) {
    return <div className="k-page"><Head title="Prism Lab Benchmarks" /><KineticNav version={version} /><main className="mx-auto max-w-7xl px-6 py-16">
        <LabNav current="/lab/benchmarks" />
        <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end"><div><p className="k-mono mb-4">Local only · aggregated from real generations</p><h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">Provider <span className="k-grad-text">benchmarks</span></h1><p className="mt-5 max-w-3xl text-lg text-[var(--k-ink-2)]">Latency, token, and cost comparisons accumulated across every test-suite run — grouped by provider, model, and feature.</p></div><div className="flex gap-2"><a className="k-btn k-btn--ghost" href={phoenixUrl} target="_blank" rel="noreferrer">Open Phoenix ↗</a>{benchmarks.length > 0 && <a className="k-btn k-btn--grad" href="/lab/benchmarks/export">Export JSON ↓</a>}</div></div>

        {benchmarks.length === 0
            ? <section className="k-card mt-12 p-8 text-center"><p className="text-lg text-[var(--k-ink-2)]">No benchmark data yet.</p><p className="k-mono mt-3">Run the <a href="/lab/tests" className="underline">test suite</a> (or <code>php artisan lab:test</code>) — every run is recorded here automatically.</p></section>
            : <>
                <p className="k-mono mt-10">{totalRuns} recorded run{totalRuns === 1 ? '' : 's'} · {benchmarks.length} provider×model×feature combination{benchmarks.length === 1 ? '' : 's'}</p>
                <section className="k-card mt-4 overflow-x-auto p-2 sm:p-4">
                    <table className="w-full min-w-[52rem] border-collapse text-left text-sm">
                        <thead className="k-mono text-[var(--k-ink-2)]"><tr className="border-b border-[var(--k-hairline)]">
                            <th className="p-3">Provider</th><th className="p-3">Model</th><th className="p-3">Feature</th>
                            <th className="p-3 text-right">Runs</th><th className="p-3 text-right">Pass</th>
                            <th className="p-3 text-right">Avg ms</th><th className="p-3 text-right">p95 ms</th>
                            <th className="p-3 text-right">Prompt tok</th><th className="p-3 text-right">Completion tok</th><th className="p-3 text-right">Cost</th>
                        </tr></thead>
                        <tbody>{benchmarks.map(row => <tr key={`${row.provider}-${row.model}-${row.feature}`} className="border-b border-[var(--k-hairline)] last:border-0">
                            <td className="p-3 font-semibold">{row.provider}</td>
                            <td className="k-mono p-3">{row.model}</td>
                            <td className="p-3">{row.feature}</td>
                            <td className="p-3 text-right">{row.runs}</td>
                            <td className={`p-3 text-right ${row.pass_rate === 100 ? 'text-[var(--k-cyan)]' : 'text-[var(--k-mag)]'}`}>{row.pass_rate}%</td>
                            <td className="p-3 text-right">{show(row.avg_latency_ms)}</td>
                            <td className="p-3 text-right text-[var(--k-ink-2)]">{show(row.p95_latency_ms)}</td>
                            <td className="p-3 text-right">{show(row.avg_prompt_tokens)}</td>
                            <td className="p-3 text-right">{show(row.avg_completion_tokens)}</td>
                            <td className="p-3 text-right">{row.total_cost === null ? '—' : `$${row.total_cost}`}</td>
                        </tr>)}</tbody>
                    </table>
                </section>
                <p className="k-mono mt-4 text-[var(--k-ink-2)]">Cost is provider-reported where available; otherwise derive it in Phoenix from the exported token counts.</p>
            </>}
    </main><KineticFooter /></div>;
}
