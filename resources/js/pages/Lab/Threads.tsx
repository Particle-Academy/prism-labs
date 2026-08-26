import { ContentRenderer } from '@particle-academy/react-fancy';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav, labBlurb } from '../../components/lab-nav';

type Message = {
    role: string;
    class: string;
    text: string;
    tool_calls: { name: string; arguments: unknown }[];
    tool_results: { name: string; result: unknown }[];
    additional: Record<string, string>;
};

type Thread = {
    id: number;
    scope: string;
    participant: string | null;
    message_count: number;
    updated_at: string | null;
    messages: Message[];
};

const ROLE_COLOUR: Record<string, string> = {
    user: 'var(--k-cyan)',
    assistant: 'var(--k-mag)',
    system: 'var(--k-ink-3)',
    tool: 'var(--k-ink-2)',
};

export default function Threads({ version, threads }: { version: string; threads: Thread[] }) {
    const [openId, setOpenId] = useState<number | null>(threads[0]?.id ?? null);

    return (
        <div className="k-page">
            <Head title="Prism Lab Threads" />
            <KineticNav version={version} />

            <main className="mx-auto max-w-7xl px-6 py-16">
                <LabNav current="/lab/threads" />

                <p className="k-mono mb-4">Local only · harness storage</p>
                <h1 className="text-5xl font-extrabold tracking-[-.05em] sm:text-7xl">
                    Stored <span className="k-grad-text">threads</span>
                </h1>
                <p className="mt-5 max-w-3xl text-lg" style={{ color: 'var(--k-ink-2)' }}>
                    {labBlurb('/lab/threads')}
                </p>

                {threads.length === 0 ? (
                    <p className="k-mono mt-16" style={{ color: 'var(--k-ink-2)' }}>
                        Nothing stored yet — run a generation in the chat console and it will appear here.
                    </p>
                ) : (
                    <div className="mt-12 flex flex-col gap-4">
                        {threads.map(thread => (
                            <section
                                key={thread.id}
                                className="rounded-xl border"
                                style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}
                            >
                                <button
                                    type="button"
                                    onClick={() => setOpenId(openId === thread.id ? null : thread.id)}
                                    aria-expanded={openId === thread.id}
                                    className="flex w-full cursor-pointer flex-wrap items-center gap-x-6 gap-y-2 px-6 py-4 text-left"
                                >
                                    <span aria-hidden="true" style={{ color: 'var(--k-mag)' }}>
                                        {openId === thread.id ? '⌄' : '›'}
                                    </span>
                                    <span className="font-mono text-sm font-semibold break-all" style={{ color: 'var(--k-ink)' }}>
                                        {/* Not k-mono either: a scope carries a session id, and
                                            uppercasing an opaque identifier misrepresents it. */}
                                        {thread.scope}
                                    </span>
                                    <span className="k-mono text-sm" style={{ color: 'var(--k-ink-3)' }}>
                                        {thread.participant ?? 'no participant'}
                                    </span>
                                    <span className="k-mono ml-auto text-sm tabular-nums" style={{ color: 'var(--k-ink-2)' }}>
                                        {thread.message_count} message{thread.message_count === 1 ? '' : 's'}
                                        {thread.updated_at ? ` · ${thread.updated_at}` : ''}
                                    </span>
                                </button>

                                {openId === thread.id && (
                                    <div className="border-t px-6 py-5" style={{ borderColor: 'var(--k-hairline)' }}>
                                        <div className="flex flex-col gap-5">
                                            {thread.messages.map((message, i) => (
                                                <article key={i} className="border-l pl-5" style={{ borderColor: 'var(--k-hairline-2)' }}>
                                                    <header className="mb-2 flex flex-wrap items-baseline gap-3">
                                                        <span
                                                            className="k-mono text-xs uppercase tracking-wider"
                                                            style={{ color: ROLE_COLOUR[message.role] ?? 'var(--k-ink-3)' }}
                                                        >
                                                            {message.role}
                                                        </span>
                                                        {/* The rebuilt class, not the stored JSON. This is the
                                                            column that would have caught v0.1.1 flattening a
                                                            value object into an array. */}
                                                        <span className="k-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>
                                                            {message.class}
                                                        </span>
                                                    </header>

                                                    {message.text !== '' && (
                                                        // Sanitised by default, and it must stay that way: a stored
                                                        // message is model output, and a model can be talked into
                                                        // emitting markup. `unsafe` would render it.
                                                        <ContentRenderer
                                                            value={message.text}
                                                            format="markdown"
                                                            className="text-[var(--k-ink-2)]"
                                                        />
                                                    )}

                                                    {message.tool_calls.map((call, j) => (
                                                        <p key={j} className="mt-2 font-mono text-sm" style={{ color: 'var(--k-ink-3)' }}>
                                                            {/* Deliberately not k-mono: it uppercases, which is right for a
                                                                label and wrong for a JSON payload whose keys are case
                                                                sensitive and whose values are the evidence. */}
                                                            → called <strong style={{ color: 'var(--k-ink-2)' }}>{call.name}</strong>
                                                            {' '}with {JSON.stringify(call.arguments)}
                                                        </p>
                                                    ))}

                                                    {message.tool_results.map((result, j) => (
                                                        <p key={j} className="mt-2 font-mono text-sm break-all" style={{ color: 'var(--k-ink-3)' }}>
                                                            ← <strong style={{ color: 'var(--k-ink-2)' }}>{result.name}</strong>
                                                            {' '}returned {JSON.stringify(result.result)}
                                                        </p>
                                                    ))}

                                                    {Object.keys(message.additional).length > 0 && (
                                                        <p className="mt-2 font-mono text-xs" style={{ color: 'var(--k-ink-4)' }}>
                                                            {Object.entries(message.additional)
                                                                .map(([key, type]) => `${key}: ${type}`)
                                                                .join(' · ')}
                                                        </p>
                                                    )}
                                                </article>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </section>
                        ))}
                    </div>
                )}
            </main>

            <KineticFooter />
        </div>
    );
}
