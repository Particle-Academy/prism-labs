import { Head } from '@inertiajs/react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Burn = { period: string; input_tokens: number; output_tokens: number; reasoning_tokens: number; priced_cost: number; operations: number; unpriced_operations: number; cost_completeness: number | null };
type Operation = { id: string; kind: string; provider: string | null; model: string | null; language: string | null; status: string; duration_ms: number | null; input_tokens: number | null; output_tokens: number | null; reasoning_tokens: number | null; cost: string | null; cost_source: string; started_at: string };

// Reasoning tokens are a BREAKDOWN of the output count, not an addition to it:
// Anthropic reports 1240 thinking INSIDE 2820 output, and OpenAI's
// reasoning_tokens works the same way. So the total is input + output, and
// adding reasoning on top counts the expensive half twice.
//
// This was latent rather than harmless. Anthropic's thoughtTokens was null on
// every call until prism#33 was fixed, so the wrong sum happened to produce the
// right number; the first Anthropic run after that fix would have inflated
// every total here by the thinking count. Found by dogfooding the fix before
// tagging it, which is the entire argument for doing that.
function totalTokens(input: number | null, output: number | null) {
    return (input ?? 0) + (output ?? 0);
}

function BurnCard({ burn }: { burn: Burn }) {
    const tokens = totalTokens(burn.input_tokens, burn.output_tokens);
    return <section className="k-card p-6"><p className="k-mono uppercase">{burn.period} burn</p><p className="mt-3 text-4xl font-extrabold">{tokens.toLocaleString()} tok</p><p className="k-mono mt-1 text-[var(--k-ink-2)]">of which {burn.reasoning_tokens.toLocaleString()} reasoning</p><p className="mt-2 text-[var(--k-ink-2)]">${burn.priced_cost.toFixed(6)} priced · {burn.operations} billable operations</p><p className="k-mono mt-3">Cost coverage: {burn.cost_completeness === null ? '—' : `${burn.cost_completeness}%`} · {burn.unpriced_operations} unpriced</p></section>;
}

export default function Telemetry({ version, daily, monthly, recent }: { version: string; daily: Burn; monthly: Burn; recent: Operation[] }) {
    return <div className="k-page"><Head title="Prism Lab Telemetry" /><KineticNav version={version} /><main className="mx-auto max-w-7xl px-6 py-16"><LabNav current="/lab/telemetry" /><p className="k-mono mb-4">Sidecar ledger · content capture disabled</p><h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">Operation <span className="k-grad-text">economics</span></h1><p className="mt-5 max-w-3xl text-lg text-[var(--k-ink-2)]">{labBlurb('/lab/telemetry')}</p><div className="mt-10 grid gap-4 md:grid-cols-2"><BurnCard burn={daily} /><BurnCard burn={monthly} /></div><section className="k-card mt-8 overflow-x-auto p-4"><table className="w-full min-w-[52rem] text-left text-sm"><thead className="k-mono"><tr><th className="p-3">Operation</th><th className="p-3">Provider / model</th><th className="p-3">Status</th><th className="p-3 text-right">Duration</th><th className="p-3 text-right">Tokens</th><th className="p-3 text-right">Cost</th></tr></thead><tbody>{recent.map(row => <tr key={row.id} className="border-t border-[var(--k-hairline)]"><td className="p-3">{row.kind}{row.language ? ` · ${row.language}` : ''}</td><td className="k-mono p-3">{row.provider ?? '—'} / {row.model ?? '—'}</td><td className="p-3">{row.status}</td><td className="p-3 text-right">{row.duration_ms === null ? '—' : `${row.duration_ms}ms`}</td><td className="p-3 text-right">{totalTokens(row.input_tokens, row.output_tokens).toLocaleString()}{row.reasoning_tokens ? <span className="k-mono block text-xs text-[var(--k-ink-2)]">{row.reasoning_tokens.toLocaleString()} reasoning</span> : null}</td><td className="p-3 text-right">{row.cost_source === 'unpriced' ? 'unpriced' : `$${row.cost}`}</td></tr>)}</tbody></table></section></main><KineticFooter /></div>;
}
