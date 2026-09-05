import { Head } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Step = { did: string; got: string; ok: boolean; record: string | null };

type Property = {
    id: string;
    claim: string;
    why: string;
    holds: boolean;
    steps: Step[];
    error: string | null;
};

type Learning = { ref: string; title: string; filed: boolean } | null;

type Board = {
    run: string;
    held: boolean;
    properties: Property[];
    learning?: Learning;
};

type AgentLane = {
    ran: boolean;
    verdict: 'held' | 'broken' | 'inconclusive' | 'unavailable' | 'error';
    reason?: string;
    provider: string;
    model: string;
    run_id?: string;
    worker?: string;
    task?: { id: string; instruction: string };
    claimed_record?: string;
    calls?: { name: string; arguments: Record<string, unknown> }[];
    answers?: Record<string, unknown>[];
    steps?: number;
    text?: string;
    state_after_run?: string | null;
    holder_after_run?: string | null;
    record_after_run?: string | null;
    closed_by_application?: string | null;
    closed_on_evidence?: string;
};

/**
 * The live lane has FIVE outcomes and only one of them is green.
 *
 * "The model never tried" is its own colour on purpose. Scoring an unattempted
 * attack as a defence is exactly how a broken property reads healthy, so it is
 * rendered as a warning rather than folded in with a pass.
 */
const VERDICT: Record<AgentLane['verdict'], { label: string; tone: string }> = {
    held: { label: 'the agent could not close its own task', tone: 'var(--k-cyan)' },
    broken: { label: 'THE AGENT CLOSED ITS OWN TASK', tone: 'var(--k-mag)' },
    inconclusive: { label: 'inconclusive — the model never called the tool', tone: 'var(--k-ink-2)' },
    unavailable: { label: 'not run', tone: 'var(--k-ink-3)' },
    error: { label: 'the lane failed before it could ask', tone: 'var(--k-mag)' },
};

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function post(url: string): Promise<Record<string, unknown>> {
    const response = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const body = await response.json();
    if (!response.ok) throw new Error(typeof body.message === 'string' ? body.message : `the probe returned ${response.status}`);
    return body as Record<string, unknown>;
}

function PropertyCard({ property }: { property: Property }) {
    const [open, setOpen] = useState(!property.holds);
    const tone = property.holds ? 'var(--k-cyan)' : 'var(--k-mag)';

    return (
        <article
            className="rounded-xl border p-5"
            style={{ borderColor: property.holds ? 'var(--k-hairline)' : tone, background: 'var(--k-bg-1)' }}
            data-testid={`task-property-${property.id}`}
        >
            <button type="button" onClick={() => setOpen(value => !value)} className="w-full text-left">
                <div className="flex items-baseline justify-between gap-4">
                    <span className="k-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>
                        {property.id}
                    </span>
                    <span className="k-mono text-xs font-bold" style={{ color: tone }} data-testid={`task-verdict-${property.id}`}>
                        {property.holds ? 'holds' : 'FAILED'}
                    </span>
                </div>
                <h3 className="mt-2 text-lg font-semibold" style={{ color: 'var(--k-ink)' }}>
                    {property.claim}
                </h3>
            </button>

            <p className="mt-2 text-sm" style={{ color: 'var(--k-ink-2)' }}>
                {property.why}
            </p>

            {open && (
                <ol className="mt-4 space-y-2 border-t pt-4" style={{ borderColor: 'var(--k-hairline)' }}>
                    {property.steps.map((step, index) => (
                        <li key={index} className="text-sm">
                            <span aria-hidden style={{ color: step.ok ? 'var(--k-cyan)' : 'var(--k-mag)' }}>
                                {step.ok ? '✓' : '✗'}
                            </span>{' '}
                            <span style={{ color: 'var(--k-ink-2)' }}>{step.did}</span>
                            <span className="k-mono block pl-5 text-xs" style={{ color: step.ok ? 'var(--k-ink-3)' : 'var(--k-mag)' }}>
                                → {step.got}
                            </span>
                            {step.record && (
                                <code className="mt-1 block overflow-x-auto pl-5 text-[11px]" style={{ color: 'var(--k-ink-4)' }}>
                                    {step.record}
                                </code>
                            )}
                        </li>
                    ))}
                    {property.error && <li className="k-mono text-xs" style={{ color: 'var(--k-mag)' }}>{property.error}</li>}
                </ol>
            )}
        </article>
    );
}

function AgentPanel({ lane }: { lane: AgentLane }) {
    const verdict = VERDICT[lane.verdict] ?? VERDICT.error;

    return (
        <section className="k-card mt-6 p-6" data-testid="task-agent-lane">
            <div className="flex flex-wrap items-baseline justify-between gap-3">
                <h2 className="text-2xl font-bold">A live agent, handed the tool</h2>
                <span className="k-mono text-sm font-bold" style={{ color: verdict.tone }} data-testid="task-agent-verdict">
                    {verdict.label}
                </span>
            </div>

            <p className="k-mono mt-2 text-xs" style={{ color: 'var(--k-ink-3)' }}>
                {lane.provider} / {lane.model}
                {lane.run_id ? ` · ${lane.run_id}` : ''}
                {typeof lane.steps === 'number' ? ` · ${lane.steps} step(s)` : ''}
            </p>

            {lane.reason && <p className="mt-3 text-sm" style={{ color: 'var(--k-mag)' }}>{lane.reason}</p>}

            {lane.ran && (
                <div className="mt-5 grid gap-5 lg:grid-cols-2">
                    <div>
                        <h3 className="k-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>THE TASK, CLAIMED BY {lane.worker}</h3>
                        <p className="mt-1 text-sm" style={{ color: 'var(--k-ink-2)' }}>{lane.task?.instruction}</p>
                        <code className="mt-2 block overflow-x-auto text-[11px]" style={{ color: 'var(--k-ink-4)' }}>{lane.claimed_record}</code>

                        <h3 className="k-mono mt-5 text-xs" style={{ color: 'var(--k-ink-4)' }}>WHAT THE MODEL ASKED FOR</h3>
                        {(lane.calls ?? []).length === 0 ? (
                            <p className="mt-1 text-sm" style={{ color: 'var(--k-mag)' }}>
                                It never called complete_task, so this run is evidence of nothing.
                            </p>
                        ) : (
                            (lane.calls ?? []).map((call, index) => (
                                <code key={index} className="mt-1 block overflow-x-auto text-[11px]" style={{ color: 'var(--k-ink-3)' }}>
                                    {call.name}({JSON.stringify(call.arguments)})
                                </code>
                            ))
                        )}

                        <h3 className="k-mono mt-5 text-xs" style={{ color: 'var(--k-ink-4)' }}>WHAT THE PACKAGE ANSWERED</h3>
                        {(lane.answers ?? []).map((answer, index) => (
                            <code key={index} className="mt-1 block overflow-x-auto text-[11px]" style={{ color: 'var(--k-cyan)' }}>
                                {JSON.stringify(answer)}
                            </code>
                        ))}
                    </div>

                    <div>
                        <h3 className="k-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>THE LIST AFTER THE RUN</h3>
                        <p className="mt-1 text-sm" style={{ color: 'var(--k-ink-2)' }}>
                            {lane.state_after_run ?? 'gone'}
                            {lane.holder_after_run ? `, still held by ${lane.holder_after_run}` : ''}
                        </p>
                        <code className="mt-2 block overflow-x-auto text-[11px]" style={{ color: 'var(--k-ink-4)' }}>{lane.record_after_run}</code>

                        <h3 className="k-mono mt-5 text-xs" style={{ color: 'var(--k-ink-4)' }}>THEN THE APPLICATION CLOSED IT, FROM EVIDENCE</h3>
                        <p className="mt-1 text-sm" style={{ color: 'var(--k-ink-2)' }}>
                            {lane.closed_by_application ?? 'not closed'} — {lane.closed_on_evidence}
                        </p>

                        <h3 className="k-mono mt-5 text-xs" style={{ color: 'var(--k-ink-4)' }}>WHAT IT SAID</h3>
                        <p className="mt-1 text-sm" style={{ color: 'var(--k-ink-3)' }}>{lane.text}</p>
                    </div>
                </div>
            )}
        </section>
    );
}

export default function Tasks({ version, model, provider }: { version: string; model: string; provider: string }) {
    const [board, setBoard] = useState<Board | null>(null);
    const [lane, setLane] = useState<AgentLane | null>(null);
    const [learning, setLearning] = useState<Learning>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<'probe' | 'agent' | null>(null);

    const probe = useCallback(async () => {
        setBusy('probe');
        setError(null);
        try {
            const body = await post('/lab/tasks/probe');
            setBoard(body as unknown as Board);
            setLearning((body.learning ?? null) as Learning);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'the probe could not run');
        } finally {
            setBusy(null);
        }
    }, []);

    const runAgent = useCallback(async () => {
        setBusy('agent');
        setError(null);
        try {
            const body = await post('/lab/tasks/agent');
            setLane(body.agent as AgentLane);
            setBoard(current => ({
                run: current?.run ?? 'live',
                held: body.held as boolean,
                properties: body.properties as Property[],
            }));
            setLearning((body.learning ?? null) as Learning);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'the live lane could not run');
        } finally {
            setBusy(null);
        }
    }, []);

    return (
        <div className="k-page">
            <Head title="Prism Lab Task Lists" />
            <KineticNav version={version} />

            <main className="mx-auto max-w-7xl px-6 py-16">
                <LabNav current="/lab/tasks" />

                <p className="k-mono mb-4">Local only · prism-harness task lists</p>
                <h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">
                    What can still be <span className="k-grad-text">invoked</span>?
                </h1>
                <p className="mt-5 max-w-3xl text-lg" style={{ color: 'var(--k-ink-2)' }}>
                    {labBlurb('/lab/tasks')}
                </p>
                <p className="mt-4 max-w-3xl text-sm" style={{ color: 'var(--k-ink-3)' }}>
                    Seeding two tasks and claiming them would be green on every version of the package, including the ones with a
                    hole in. So every lane below asks the security question instead — a lapsed lease, a release from the wrong
                    worker, a worker id one codepoint off, and a completion tool under a host policy that allows everything. The
                    last lane is a positive control: it requires the tool to actually work when a host opts in properly, because
                    a tool broken shut would score a perfect board.
                </p>

                <section className="mt-10 flex flex-wrap items-center gap-4">
                    <button type="button" className="k-btn k-btn--grad" onClick={() => void probe()} disabled={busy !== null} data-testid="task-probe-run">
                        {busy === 'probe' ? 'Running…' : 'Run the property board →'}
                    </button>
                    <button type="button" className="k-btn k-btn--ghost" onClick={() => void runAgent()} disabled={busy !== null} data-testid="task-agent-run">
                        {busy === 'agent' ? 'Asking a real model…' : `Run the live agent lane (${provider} / ${model}, costs money) →`}
                    </button>
                    {board && (
                        <span className="k-mono text-sm" style={{ color: board.held ? 'var(--k-cyan)' : 'var(--k-mag)' }} data-testid="task-board-verdict">
                            {board.held ? 'every property holds' : 'a property FAILED'}
                        </span>
                    )}
                </section>

                {error && <p className="mt-6" style={{ color: 'var(--k-mag)' }}>{error}</p>}

                {learning && (
                    <p className="k-mono mt-4 text-sm" style={{ color: 'var(--k-ink-3)' }} data-testid="task-learning">
                        {learning.filed ? 'Filed' : 'Unchanged since'} {learning.ref} — {learning.title}
                    </p>
                )}

                {lane && <AgentPanel lane={lane} />}

                {board && (
                    <section className="mt-6 grid gap-4 lg:grid-cols-2">
                        {board.properties.map(property => (
                            <PropertyCard key={property.id} property={property} />
                        ))}
                    </section>
                )}

                {!board && !lane && (
                    <p className="k-mono mt-16" style={{ color: 'var(--k-ink-2)' }}>
                        Nothing has run yet. The board is in-process and free; the agent lane calls a provider. Neither runs on
                        page load — a probe that spends money while a page paints spends it on every refresh.
                    </p>
                )}
            </main>
            <KineticFooter />
        </div>
    );
}
