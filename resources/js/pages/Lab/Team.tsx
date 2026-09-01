import { Badge, Callout, ContentRenderer } from '@particle-academy/react-fancy';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Reported = {
    port_version?: string | null;
    provider?: string | null;
    model?: string | null;
    can_reason?: boolean;
    /** False when the lane is pointed at a provider its port does not implement. */
    provider_available?: boolean;
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

type Disagreement = {
    suite: string;
    case_id: string;
    statuses: Record<string, string>;
    reasons: Record<string, string | null>;
};

type Parity = {
    corpus_version: string;
    corpus_digest: string;
    ran_at: string | null;
    totals: Record<string, Record<string, number>>;
    disagreements: Disagreement[];
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

                {member.reported?.provider && (
                    <>
                        <dt style={{ color: 'var(--k-ink-4)' }}>Provider</dt>
                        <dd
                            className="font-mono"
                            style={{
                                // A lane pointed at a provider its port cannot route to
                                // would otherwise look healthy right up until the first
                                // billable call fails.
                                color: member.reported.provider_available === false ? 'var(--k-mag)' : 'var(--k-ink-2)',
                            }}
                        >
                            {member.reported.provider}
                            {member.reported.provider_available === false && ' · not in this port'}
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
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState<string | null>(null);
    const tone = SEVERITY_TONE[learning.severity] ?? 'var(--k-ink-3)';

    async function nudge(event: React.MouseEvent) {
        // The card header is a toggle; without this the click both sends the
        // report and collapses the thing you were reading.
        event.stopPropagation();

        if (sending) return;

        setSending(true);
        setSent(null);

        try {
            const response = await fetch('/lab/team/nudge', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ ref: learning.ref }),
            });

            const body = await response.json();
            setSent(response.ok ? (body.message ?? 'Sent.') : (body.message ?? 'Could not deliver it.'));
        } catch {
            setSent('Could not reach the Lab.');
        } finally {
            setSending(false);
        }
    }

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

                {/* Rendered as a span with a click handler rather than a nested
                    <button>: the whole header is already a button, and a button
                    inside a button is invalid markup that browsers resolve in
                    their own ways. */}
                <span
                    role="button"
                    tabIndex={0}
                    onClick={nudge}
                    onKeyDown={event => {
                        if (event.key === 'Enter' || event.key === ' ') nudge(event as unknown as React.MouseEvent);
                    }}
                    className="k-mono cursor-pointer rounded-lg border px-2.5 py-1 text-xs transition-colors hover:text-[var(--k-cyan)]"
                    style={{ borderColor: 'var(--k-hairline-2)', color: 'var(--k-ink-3)' }}
                    title="Send this report to the coding agent working in this workspace"
                >
                    {sending ? 'sending…' : 'send to agent'}
                </span>
            </button>

            {sent && (
                <p className="k-mono border-t px-5 py-2 text-xs" style={{ borderColor: 'var(--k-hairline)', color: 'var(--k-ink-3)' }}>
                    {sent}
                </p>
            )}

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

/**
 * Cross-language conformance.
 *
 * Totals are shown, but they are the least useful thing here: the first run
 * reported 46 pass and 3 skip in BOTH languages while disagreeing on two
 * cases. The disagreement list is the point, and it leads.
 */
function ParityPanel({ parity }: { parity: Parity | null }) {
    if (parity === null) {
        return (
            <p className="text-sm" style={{ color: 'var(--k-ink-3)' }}>
                No conformance run recorded yet. Run <code>php artisan team:conformance</code> — it builds each port
                first, so give it a moment.
            </p>
        );
    }

    const languages = Object.keys(parity.totals).sort();

    return (
        <div className="flex flex-col gap-5">
            <p className="k-mono text-xs break-all" style={{ color: 'var(--k-ink-4)' }}>
                corpus {parity.corpus_version} · {parity.corpus_digest}
                {parity.ran_at ? ` · ${parity.ran_at}` : ''}
            </p>

            <div className="flex flex-wrap gap-3">
                {languages.map(language => (
                    <div
                        key={language}
                        className="rounded-xl border px-4 py-3"
                        style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                    >
                        <div className="k-mono text-xs" style={{ color: 'var(--k-ink-3)' }}>
                            {language}
                        </div>
                        <div className="mt-1 flex gap-3 text-sm tabular-nums">
                            {Object.entries(parity.totals[language]).map(([status, count]) => (
                                <span key={status} style={{ color: status === 'fail' ? 'var(--k-mag)' : 'var(--k-ink-2)' }}>
                                    {count} {status}
                                </span>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            {parity.disagreements.length === 0 ? (
                // Stated, not implied by an empty list. "Nothing rendered" and
                // "the languages agree" are different claims.
                <p className="text-sm" style={{ color: 'var(--k-ink-2)' }}>
                    The languages agree on every case in this corpus.
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    <p className="k-mono text-xs" style={{ color: 'var(--k-mag)' }}>
                        {parity.disagreements.length} case(s) where the languages disagree
                    </p>

                    {parity.disagreements.map(row => (
                        <article
                            key={`${row.suite}/${row.case_id}`}
                            className="rounded-xl border p-4"
                            style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                        >
                            <header className="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span className="font-mono text-sm" style={{ color: 'var(--k-ink)' }}>
                                    {row.suite}/{row.case_id}
                                </span>
                                {Object.entries(row.statuses).map(([language, status]) => (
                                    <span key={language} className="k-mono text-xs" style={{ color: 'var(--k-ink-3)' }}>
                                        {language}=<strong style={{ color: 'var(--k-cyan)' }}>{status}</strong>
                                    </span>
                                ))}
                            </header>

                            {Object.entries(row.reasons)
                                .filter(([, reason]) => Boolean(reason))
                                .map(([language, reason]) => (
                                    <p key={language} className="mt-2 text-sm leading-6" style={{ color: 'var(--k-ink-3)' }}>
                                        <span className="k-mono text-xs">{language}:</span> {reason}
                                    </p>
                                ))}
                        </article>
                    ))}
                </div>
            )}
        </div>
    );
}

interface HarnessStep {
    step: string;
    observed: unknown;
    expected: unknown;
    ok: boolean;
}

interface HarnessLane {
    agent: string;
    language: string;
    reachable: boolean;
    reason: string | null;
    report: {
        ok: boolean;
        package: string;
        session_key: string;
        thread_messages: number;
        steps: HarnessStep[];
    } | null;
}

interface EcosystemCheck {
    step: string;
    ok: boolean;
}

interface EcosystemFamily {
    family: string;
    checks: EcosystemCheck[];
}

interface EcosystemLane {
    agent: string;
    language: string;
    reachable: boolean;
    reason: string | null;
    report: {
        ok: boolean;
        language: string;
        passed: number;
        total: number;
        families: EcosystemFamily[];
    } | null;
}

/**
 * The six satellite ports, exercised end to end in both languages.
 *
 * Rendered by FAMILY rather than by lane, which is the difference that makes it
 * worth having: a family is green only when both languages agree it is, and a
 * port that passes in TypeScript while failing in Python is exactly the parity
 * failure two languages exist to catch. Grouping by lane would show two green
 * columns and hide the disagreement between them.
 */
function EcosystemPanel() {
    const [lanes, setLanes] = useState<EcosystemLane[] | null>(null);
    const [families, setFamilies] = useState<Record<string, boolean>>({});
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);

    const probe = useCallback(async () => {
        setRunning(true);
        setError(null);
        try {
            const response = await fetch('/lab/team/ecosystem', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`the ecosystem probe returned ${response.status}`);
            const body = await response.json();
            setLanes(body.lanes);
            setFamilies(body.families ?? {});
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'could not reach the lanes');
        } finally {
            setRunning(false);
        }
    }, []);

    useEffect(() => {
        void probe();
    }, [probe]);

    // Every check name a lane reported, per family. Names are identical across
    // languages by construction — the two probes mirror each other check for
    // check — so a name appearing in one lane and not the other is itself the
    // signal that they have drifted apart.
    const rows = (name: string) => {
        const seen = new Map<string, Record<string, boolean>>();

        for (const lane of lanes ?? []) {
            for (const family of lane.report?.families ?? []) {
                if (family.family !== name) continue;

                for (const check of family.checks) {
                    const row = seen.get(check.step) ?? {};

                    row[lane.language] = check.ok;
                    seen.set(check.step, row);
                }
            }
        }

        return [...seen.entries()];
    };

    const languages = (lanes ?? []).map(lane => lane.language);

    return (
        <div data-testid="ecosystem-panel">
            <div className="mb-4 flex items-center gap-4">
                <button
                    type="button"
                    className="k-btn k-btn--ghost"
                    onClick={() => void probe()}
                    disabled={running}
                    data-testid="ecosystem-rerun"
                >
                    {running ? 'Running…' : 'Run again'}
                </button>
                {lanes !== null && (
                    <span className="k-mono text-xs opacity-70" data-testid="ecosystem-summary">
                        {lanes
                            .map(lane =>
                                lane.report
                                    ? `${lane.language} ${lane.report.passed}/${lane.report.total}`
                                    : `${lane.language} unreachable`,
                            )
                            .join(' · ')}
                    </span>
                )}
            </div>

            {error && <Callout>{error}</Callout>}

            <div className="grid gap-4 lg:grid-cols-2">
                {lanes === null
                    ? Array.from({ length: 6 }, (_, i) => (
                          <div
                              key={i}
                              className="h-48 animate-pulse rounded-xl border"
                              style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                          />
                      ))
                    : Object.keys(families).map(name => (
                          <div
                              key={name}
                              className="rounded-xl border p-4"
                              style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                              data-testid={`ecosystem-family-${name}`}
                          >
                              <div className="mb-3 flex items-baseline justify-between">
                                  <span className="k-mono">prism-{name}</span>
                                  <span
                                      className="k-mono text-xs"
                                      data-testid={`ecosystem-verdict-${name}`}
                                  >
                                      {families[name] ? 'both languages agree' : 'languages disagree'}
                                  </span>
                              </div>

                              <ul className="space-y-1">
                                  {rows(name).map(([step, byLanguage]) => (
                                      <li key={step} className="k-mono text-xs">
                                          <span aria-hidden>
                                              {languages
                                                  .map(language =>
                                                      byLanguage[language] === undefined
                                                          ? '·'
                                                          : byLanguage[language]
                                                            ? '✓'
                                                            : '✗',
                                                  )
                                                  .join('')}
                                          </span>{' '}
                                          <span
                                              className={
                                                  languages.every(language => byLanguage[language])
                                                      ? ''
                                                      : 'font-bold'
                                              }
                                          >
                                              {step}
                                          </span>
                                      </li>
                                  ))}
                              </ul>
                          </div>
                      ))}
            </div>
        </div>
    );
}

/**
 * The harness ports, exercised end to end in both languages.
 *
 * Asked of the AGENTS, not run here. That is the whole claim: the Lab is a
 * consumer reaching a package over the wire, in a process that did not build
 * it. Running the same scenario inside this app would only prove PHP can call
 * PHP.
 */
function HarnessPanel() {
    const [lanes, setLanes] = useState<HarnessLane[] | null>(null);
    const [agree, setAgree] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);

    const probe = useCallback(async () => {
        setRunning(true);
        setError(null);
        try {
            const response = await fetch('/lab/team/harness', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`the harness probe returned ${response.status}`);
            const body = await response.json();
            setLanes(body.lanes);
            setAgree(body.keys_agree ? body.shared_session_key : null);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'could not reach the lanes');
        } finally {
            setRunning(false);
        }
    }, []);

    // After paint, like the roster. Two network calls, each of which can time
    // out on a lane that is down.
    useEffect(() => {
        void probe();
    }, [probe]);

    return (
        <div data-testid="harness-panel">
            <div className="mb-4 flex items-center gap-4">
                <button
                    type="button"
                    className="k-btn k-btn--ghost"
                    onClick={() => void probe()}
                    disabled={running}
                    data-testid="harness-rerun"
                >
                    {running ? 'Running…' : 'Run again'}
                </button>
                {agree !== null && (
                    <span className="k-mono text-xs" data-testid="harness-shared-key">
                        every lane resolved the same session: <strong>{agree}</strong>
                    </span>
                )}
            </div>

            {error && <Callout>{error}</Callout>}

            <div className="grid gap-4 lg:grid-cols-2">
                {lanes === null
                    ? Array.from({ length: 2 }, (_, i) => (
                          <div
                              key={i}
                              className="h-64 animate-pulse rounded-xl border"
                              style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                          />
                      ))
                    : lanes.map(lane => (
                          <div
                              key={lane.agent}
                              className="rounded-xl border p-4"
                              style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                              data-testid={`harness-lane-${lane.language}`}
                          >
                              <div className="mb-3 flex items-baseline justify-between">
                                  <span className="k-mono">{lane.agent}</span>
                                  <span
                                      className="k-mono text-xs"
                                      data-testid={`harness-verdict-${lane.language}`}
                                  >
                                      {lane.report?.ok ? 'all properties hold' : lane.reason ?? 'failed'}
                                  </span>
                              </div>

                              {lane.report && (
                                  <>
                                      <p className="k-mono mb-3 text-xs opacity-70">
                                          {lane.report.package} · {lane.report.thread_messages} thread messages
                                      </p>
                                      <ul className="space-y-1">
                                          {lane.report.steps.map(step => (
                                              <li key={step.step} className="k-mono text-xs">
                                                  <span aria-hidden>{step.ok ? '✓' : '✗'}</span>{' '}
                                                  <span className={step.ok ? '' : 'font-bold'}>{step.step}</span>
                                              </li>
                                          ))}
                                      </ul>
                                  </>
                              )}
                          </div>
                      ))}
            </div>
        </div>
    );
}

export default function Team({
    version,
    learnings: initialLearnings,
    parity,
}: {
    version: string;
    learnings: Learning[];
    parity: Parity | null;
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

                <section className="mt-16">
                    <h2 className="k-mono mb-5">Durable sessions · the harness in three languages</h2>
                    <HarnessPanel />
                </section>

                <section className="mt-16">
                    <h2 className="k-mono mb-5">The satellites · six families, two languages</h2>
                    <EcosystemPanel />
                </section>

                <section className="mt-16">
                    <h2 className="k-mono mb-5">Language parity · conformance</h2>
                    <ParityPanel parity={parity} />
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
