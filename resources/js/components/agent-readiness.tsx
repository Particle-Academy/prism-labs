import { useEffect, useState } from 'react';

type Capability = 'consensus' | 'benchmark';
type Lane = { agent: string; language: string; port_version: string | null; capabilities: Record<Capability, { ready: boolean; reason: string | null }> };

export function AgentReadiness({ capability }: { capability: Capability }) {
    const [lanes, setLanes] = useState<Lane[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const [checking, setChecking] = useState(true);
    useEffect(() => {
        const controller = new AbortController();
        setChecking(true);
        setError(null);
        void fetch('/lab/team/readiness', { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async response => {
                if (!response.ok) throw new Error(`Agent readiness check failed (${response.status}).`);
                const result = await response.json();
                if (!Array.isArray(result.lanes)) throw new Error('The readiness response contains no lane inventory.');
                setLanes(result.lanes);
            })
            .catch(failure => { if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : 'Could not check the language agents.'); })
            .finally(() => { if (!controller.signal.aborted) setChecking(false); });
        return () => controller.abort();
    }, [attempt]);

    return <section className="lab-panel" aria-label="Language agent readiness">
        <div className="lab-panel-head"><span>Language agent readiness</span><button type="button" className="k-btn k-btn--ghost" disabled={checking} onClick={() => setAttempt(value => value + 1)}>{checking ? 'Checking agents…' : 'Check again'}</button></div>
        <p className="lab-diagnostic-note">This checks running code, credentials, and advertised tools without calling a model. It does not prove a provider call succeeds.</p>
        {error && <p role="alert">{error}</p>}
        {!checking && !error && lanes?.length === 0 && <p role="alert">No language agents are configured.</p>}
        {lanes?.map(lane => <p key={lane.language}><b>{lane.agent}</b>{lane.port_version ? ` · ${lane.port_version}` : ''}: {lane.capabilities[capability].ready ? 'Ready to collect an answer; live provider execution is unverified.' : lane.capabilities[capability].reason}</p>)}
        {capability === 'benchmark' && <p className="lab-diagnostic-note">PHP lanes run in this application. Cross-language benchmarks remain blocked until each selected port implements the lane-execution contract. Workflow, commentary, and scoring workers must also be running.</p>}
    </section>;
}
