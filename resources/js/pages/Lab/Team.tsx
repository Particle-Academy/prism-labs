import { Badge, Callout, ContentRenderer } from '@particle-academy/react-fancy';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Reported = {
    port_version?: string | null;
    model?: string | null;
    can_reason?: boolean;
    provider_count?: number;
};

type Member = {
    name: string;
    language: string;
    state: string;
    state_label: string;
    repo: string | null;
    addressable: boolean;
    note: string | null;
    reachable?: boolean;
    reported?: Reported | null;
    reason?: string | null;
};

type Learning = {
    ref: string;
    title: string;
    filed_by: string;
    severity: string;
    severity_label: string;
    languages: string[];
    what_was_learned: string;
    evidence: string;
    why_it_matters: string;
    what_should_change: string | null;
    filed_at: string | null;
};

type Turn = {
    who: 'you' | 'prism';
    text: string;
    calls?: string[];
    tokens?: { prompt: number | null; completion: number | null };
};

/** Every state gets a distinct colour, so the board reads at a glance. */
const STATE_TONE: Record<string, string> = {
    coordinator: 'var(--k-mag)',
    live: 'var(--k-cyan)',
    unreachable: 'var(--k-ink-3)',
    planned: 'var(--k-ink-4)',
};

const SEVERITY_TONE: Record<string, string> = {
    info: 'var(--k-ink-3)',
    notable: 'var(--k-cyan)',
    urgent: 'var(--k-mag)',
};

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function MemberCard({ member }: { member: Member }) {
    const tone = STATE_TONE[member.state] ?? 'var(--k-ink-3)';
    const planned = member.state === 'planned';

    return (
        <article
            className="rounded-xl border p-5"
            style={{
                borderColor: 'var(--k-hairline)',
                background: 'var(--k-bg-1)',
                // Planned lanes are deliberately dimmed rather than hidden. A
                // board that omits what does not exist reads as full coverage.
                opacity: planned ? 0.55 : 1,
            }}
        >
            <header className="mb-3 flex items-center justify-between gap-3">
                <span className="font-mono font-semibold" style={{ color: 'var(--k-ink)' }}>
                    {member.name}
                </span>
                <Badge variant="soft" size="sm" style={{ color: tone, borderColor: tone }}>
                    {member.state_label}
                </Badge>
            </header>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                <dt style={{ color: 'var(--k-ink-4)' }}>Repo</dt>
                <dd className="font-mono" style={{ color: 'var(--k-ink-2)' }}>
                    {member.repo ?? '—'}
                </dd>

                <dt style={{ color: 'var(--k-ink-4)' }}>Port</dt>
                <dd className="font-mono tabular-nums" style={{ color: 'var(--k-ink-2)' }}>
                    {member.reported?.port_version ?? '—'}
                </dd>

                <dt style={{ color: 'var(--k-ink-4)' }}>Reasons</dt>
                <dd style={{ color: 'var(--k-ink-2)' }}>
                    {member.reported?.can_reason === true ? 'yes' : member.reported?.can_reason === false ? 'no key' : '—'}
                </dd>

                {member.reported?.model && (
                    <>
                        <dt style={{ color: 'var(--k-ink-4)' }}>Model</dt>
                        <dd className="font-mono" style={{ color: 'var(--k-ink-2)' }}>
                            {member.reported.model}
                        </dd>
                    </>
                )}
            </dl>

            {member.note && (
                <p className="mt-3 text-sm leading-6" style={{ color: 'var(--k-ink-3)' }}>
                    {member.note}
                </p>
            )}

            {member.reason && (
                <p className="mt-3 font-mono text-xs break-all" style={{ color: 'var(--k-ink-4)' }}>
                    {member.reason}
                </p>
            )}
        </article>
    );
}

function LearningCard({ learning }: { learning: Learning }) {
    const [open, setOpen] = useState(false);
    const tone = SEVERITY_TONE[learning.severity] ?? 'var(--k-ink-3)';

    return (
        <article className="rounded-xl border" style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex w-full cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 text-left"
            >
                <span className="font-mono text-sm font-semibold" style={{ color: tone }}>
                    {learning.ref}
                </span>
                <span className="flex-1 text-sm font-medium" style={{ color: 'var(--k-ink)' }}>
                    {learning.title}
                </span>
                <Badge variant="soft" size="sm" style={{ color: tone, borderColor: tone }}>
                    {learning.severity_label}
                </Badge>
                <span className="k-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>
                    {learning.languages.join(' · ')}
                </span>
            </button>

            {open && (
                <div className="border-t px-5 py-4" style={{ borderColor: 'var(--k-hairline)' }}>
                    {(
                        [
                            ['What was learned', learning.what_was_learned],
                            ['Evidence', learning.evidence],
                            ['Why it matters to the ecosystem', learning.why_it_matters],
                            ['What should change', learning.what_should_change],
                        ] as const
                    )
                        .filter(([, body]) => Boolean(body))
                        .map(([heading, body]) => (
                            <section key={heading} className="mb-4 last:mb-0">
                                <h4 className="k-mono mb-1.5 text-xs" style={{ color: 'var(--k-ink-4)' }}>
                                    {heading}
                                </h4>
                                {/* Sanitised: a 0L is model-written prose. */}
                                <ContentRenderer
                                    value={String(body)}
                                    format="markdown"
                                    className="text-sm leading-6 text-[var(--k-ink-2)]"
                                />
                            </section>
                        ))}

                    <p className="k-mono mt-4 text-xs" style={{ color: 'var(--k-ink-4)' }}>
                        filed by {learning.filed_by}
                        {learning.filed_at ? ` · ${learning.filed_at}` : ''}
                    </p>
                </div>
            )}
        </article>
    );
}

export default function Team({
    version,
    learnings: initialLearnings,
}: {
    version: string;
    learnings: Learning[];
}) {
    const [roster, setRoster] = useState<Member[] | null>(null);
    const [rosterError, setRosterError] = useState<string | null>(null);
    const [learnings, setLearnings] = useState<Learning[]>(initialLearnings);
    const [turns, setTurns] = useState<Turn[]>([]);
    const [question, setQuestion] = useState('');
    const [asking, setAsking] = useState(false);
    const [askError, setAskError] = useState<string | null>(null);
    const transcript = useRef<HTMLDivElement>(null);

    const loadRoster = useCallback(async () => {
        setRosterError(null);
        try {
            const response = await fetch('/lab/team/roster', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`roster returned ${response.status}`);
            setRoster((await response.json()).roster);
        } catch (error) {
            setRosterError(error instanceof Error ? error.message : 'could not reach the board');
        }
    }, []);

    // After paint, not during. Probing costs a network call per lane, and a
    // lane that is down would otherwise hold the whole page behind its timeout.
    useEffect(() => {
        void loadRoster();
    }, [loadRoster]);

    useEffect(() => {
        transcript.current?.scrollTo({ top: transcript.current.scrollHeight, behavior: 'smooth' });
    }, [turns]);

    async function ask(event: React.FormEvent) {
        event.preventDefault();
        const asked = question.trim();
        if (asked === '' || asking) return;

        setAsking(true);
        setAskError(null);
        setQuestion('');
        setTurns(current => [...current, { who: 'you', text: asked }]);

        try {
            const response = await fetch('/lab/team/ask', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ question: asked }),
            });

            const body = await response.json();

            if (!response.ok) {
                setAskError(
                    response.status === 429
                        ? 'Rate limited — Prism delegates to teammates and each call is billable. Give it a minute.'
                        : (body.message ?? 'Something went wrong.')
                );
                return;
            }

            setTurns(current => [
                ...current,
                {
                    who: 'prism',
                    text: body.text,
                    calls: (body.tool_calls ?? []).map((call: { name: string }) => call.name),
                    tokens: body.usage,
                },
            ]);

            // A 0L may have been filed mid-answer.
            if (Array.isArray(body.learnings)) setLearnings(body.learnings);
            void loadRoster();
        } catch {
            setAskError('Could not reach the Lab.');
        } finally {
            setAsking(false);
        }
    }

    return (
        <div className="k-page">
            <Head title="Prism Lab — Team" />
            <KineticNav version={version} />

            <main className="mx-auto max-w-7xl px-6 py-16">
                <LabNav current="/lab/team" />

                <p className="k-mono mb-4">Local only · one agent per port</p>
                <h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">
                    The <span className="k-grad-text">team</span>
                </h1>
                <p className="mt-5 max-w-3xl text-lg" style={{ color: 'var(--k-ink-2)' }}>
                    {labBlurb('/lab/team')}
                </p>

                <section className="mt-12">
                    <div className="mb-5 flex items-center justify-between gap-4">
                        <h2 className="k-mono">Roster</h2>
                        <button type="button" className="k-btn k-btn--ghost" onClick={() => void loadRoster()}>
                            Refresh
                        </button>
                    </div>

                    {rosterError && <Callout>{rosterError}</Callout>}

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {roster === null
                            ? // Skeletons sized to the real card, so the board does not
                              // jump when the probes come back.
                              Array.from({ length: 5 }, (_, i) => (
                                  <div
                                      key={i}
                                      className="h-52 animate-pulse rounded-xl border"
                                      style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                                  />
                              ))
                            : roster.map(member => <MemberCard key={member.name} member={member} />)}
                    </div>
                </section>

                <section className="mt-16 grid gap-8 lg:grid-cols-[3fr_2fr]">
                    <div>
                        <h2 className="k-mono mb-5">Ask Prism</h2>

                        <div
                            ref={transcript}
                            className="mb-4 max-h-[28rem] overflow-y-auto rounded-xl border p-5"
                            style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                        >
                            {turns.length === 0 && (
                                <p className="text-sm" style={{ color: 'var(--k-ink-3)' }}>
                                    Prism coordinates the team. Ask it to compare the ports, chase a conformance failure, or
                                    check what a language actually implements — it will delegate and say who told it what.
                                </p>
                            )}

                            <div className="flex flex-col gap-5">
                                {turns.map((turn, i) => (
                                    <div key={i}>
                                        <p
                                            className="k-mono mb-1.5 text-xs uppercase"
                                            style={{ color: turn.who === 'you' ? 'var(--k-cyan)' : 'var(--k-mag)' }}
                                        >
                                            {turn.who === 'you' ? 'you' : 'Prism.php'}
                                        </p>

                                        {turn.who === 'you' ? (
                                            <p className="text-sm leading-6" style={{ color: 'var(--k-ink-2)' }}>
                                                {turn.text}
                                            </p>
                                        ) : (
                                            <>
                                                {/* Sanitised: this is model output. */}
                                                <ContentRenderer
                                                    value={turn.text}
                                                    format="markdown"
                                                    className="text-sm leading-6 text-[var(--k-ink-2)]"
                                                />
                                                <p className="k-mono mt-2 text-xs" style={{ color: 'var(--k-ink-4)' }}>
                                                    {turn.calls && turn.calls.length > 0
                                                        ? `asked: ${turn.calls.join(', ')}`
                                                        : 'answered without asking anyone'}
                                                    {turn.tokens
                                                        ? ` · ${turn.tokens.prompt ?? '—'}+${turn.tokens.completion ?? '—'} tokens`
                                                        : ''}
                                                </p>
                                            </>
                                        )}
                                    </div>
                                ))}

                                {asking && (
                                    <p className="k-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>
                                        Prism is working — it may be waiting on a teammate…
                                    </p>
                                )}
                            </div>
                        </div>

                        {askError && <Callout>{askError}</Callout>}

                        <form onSubmit={ask} className="mt-4 flex flex-col gap-3">
                            <textarea
                                value={question}
                                onChange={event => setQuestion(event.target.value)}
                                maxLength={4000}
                                rows={3}
                                className="k-card w-full resize-y p-4 leading-6"
                                style={{ color: 'var(--k-ink)' }}
                                aria-label="Ask Prism"
                                placeholder="Do the ports agree on how an absent value round-trips?"
                            />
                            <div className="flex items-center gap-4">
                                <button type="submit" className="k-btn" disabled={asking || question.trim() === ''}>
                                    {asking ? 'Working…' : 'Ask'}
                                </button>
                                <span className="k-mono text-sm" style={{ color: 'var(--k-ink-3)' }}>
                                    {question.length}/4000
                                </span>
                            </div>
                        </form>
                    </div>

                    <div>
                        <h2 className="k-mono mb-5">0L reports · learnings</h2>

                        {learnings.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--k-ink-3)' }}>
                                Nothing filed yet. Prism files a 0L when it finds something that matters beyond the run it
                                came from — each one is also written to the envelope's <code>.ai/learnings/</code>, so it is
                                committed and readable outside this app.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-3">
                                {learnings.map(learning => (
                                    <LearningCard key={learning.ref} learning={learning} />
                                ))}
                            </div>
                        )}
                    </div>
                </section>
            </main>

            <KineticFooter />
        </div>
    );
}
