import { Head } from '@inertiajs/react';
import { MicroMcpServer } from '@particle-academy/agent-integrations';
import { registerFormBridge } from '@particle-academy/agent-integrations/bridges/forms';
import { attachSseRelay } from '@particle-academy/agent-integrations/sharing';
import { useEffect, useMemo, useRef, useState } from 'react';
import { KineticFooter, KineticNav } from '../../components/kinetic';
import { LabNav } from '../../components/lab-nav';

export default function HumanPlusFixture({ version, relayUrl, sessionId, token }: { version: string; relayUrl: string; sessionId: string; token: string }) {
    const [values, setValues] = useState<Record<string, unknown>>({ title: 'Human+ fixture', notes: '' });
    const valuesRef = useRef(values);
    const [relayState, setRelayState] = useState('starting');
    const [peers, setPeers] = useState(0);
    const [submitted, setSubmitted] = useState<Record<string, unknown> | null>(null);
    useEffect(() => { valuesRef.current = values; }, [values]);
    const invitation = useMemo(() => ({ relay_url: relayUrl, session_id: sessionId, token, surface_id: 'lab-form', application: 'Prism Lab Human+ fixture' }), [relayUrl, sessionId, token]);

    useEffect(() => {
        const server = new MicroMcpServer({ info: { name: 'prism-lab-human-plus', version: '1.0.0' }, instructions: 'A controlled Lab form with stable field handles. A human may or may not be present.' });
        const bridge = registerFormBridge(server, { adapter: {
            id: 'lab-form', title: 'Prism Lab Human+ fixture',
            getFields: () => [{ name: 'title', label: 'Title', type: 'text', required: true }, { name: 'notes', label: 'Notes', type: 'textarea' }],
            getValue: name => valuesRef.current[name], getValues: () => valuesRef.current,
            setValue: (name, value) => setValues(current => ({ ...current, [name]: value })),
            setValues: next => setValues(current => ({ ...current, ...next })),
            submit: async () => { setSubmitted(valuesRef.current); return { ok: true, values: valuesRef.current }; },
            confirm: () => window.confirm('Allow the agent to submit this staged form?'),
        }, pendingMode: true, agent: { id: 'prism-lab', name: 'Prism Lab', color: '#8b5cf6' } });
        let relay: ReturnType<typeof attachSseRelay> | null = null;
        fetch(`${relayUrl}/register`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session: sessionId, token }) })
            .then(response => { if (!response.ok) throw new Error('relay registration failed'); return response.json(); })
            .then(() => {
                relay = attachSseRelay(server, { baseUrl: relayUrl, sessionId, token, agent: { id: 'prism-lab', name: 'Prism Lab', color: '#8b5cf6' } });
                relay.onStateChange(setRelayState); relay.onPeersChange(setPeers); relay.start();
            }).catch(() => setRelayState('error'));
        return () => { bridge.dispose(); relay?.close(); };
    }, [relayUrl, sessionId, token]);

    return <div className="k-page"><Head title="Human+ Fixture" /><KineticNav version={version} /><main className="mx-auto max-w-5xl px-6 py-16"><LabNav current="/lab/human-plus-fixture" /><p className="k-mono">Active browser session · relay {relayState} · {peers} agent peer{peers === 1 ? '' : 's'}</p><h1 className="mt-4 text-5xl font-extrabold tracking-[-.05em]">Human+ <span className="k-grad-text">fixture</span></h1><p className="mt-4 text-[var(--k-ink-2)]">This page is the running Fancy-owned surface. Its form is controlled JSON state with stable handles and a staged submit gate. It remains Human+ whether a human is present or not.</p><section className="k-card mt-8 grid gap-5 p-8"><label><span className="k-mono mb-2 block">title · stable handle: title</span><input className="lab-input" value={String(values.title ?? '')} onChange={event => setValues(current => ({ ...current, title: event.target.value }))} /></label><label><span className="k-mono mb-2 block">notes · stable handle: notes</span><textarea className="lab-input min-h-40" value={String(values.notes ?? '')} onChange={event => setValues(current => ({ ...current, notes: event.target.value }))} /></label>{submitted && <pre className="rounded-xl bg-[var(--k-bg-2)] p-4 text-xs">Submitted: {JSON.stringify(submitted, null, 2)}</pre>}</section><section className="k-card mt-6 p-6"><p className="k-mono">Lab invitation</p><pre className="mt-3 overflow-auto text-xs">{JSON.stringify({ ...invitation, token: `${token.slice(0, 6)}…` }, null, 2)}</pre></section></main><KineticFooter /></div>;
}
