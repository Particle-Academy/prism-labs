export type PaidRunRequest = {
    action: string;
    resource: string;
    payload: Record<string, unknown>;
    resolve: (id: string) => void;
    reject: (reason: Error) => void;
};

const actions: Record<string, string> = {
    '/lab/provider-probes/research': 'provider.research', '/lab/provider-probes/cache': 'provider.cache',
    '/lab/consensus': 'consensus.collect', '/lab/team/ask': 'team.ask', '/lab/tasks/agent': 'tasks.agent',
    '/lab/benchmarks/policy-turn': 'benchmarks.policy-turn', '/lab/chat': 'chat.run', '/lab/agent': 'agent.send',
    '/lab/agent/voice': 'agent.voice', '/lab/acp': 'acp.send', '/lab/tests': 'tests.run',
    '/lab/benchmarks/compaction-probe': 'benchmarks.compaction-probe',
    '/lab/benchmarks/compaction-recall': 'benchmarks.compaction-recall', '/lab/benchmarks/summary-loss': 'benchmarks.summary-loss',
    '/lab/team/nudge': 'team.nudge', '/lab/evidence/send': 'evidence.send',
};

/** Approval stays in a human-only surface; callers receive only the approved reference. */
export function requestPaidRun(path: string, payload: Record<string, unknown>): Promise<string> {
    const benchmark = /^\/lab\/benchmarks\/([a-f0-9-]{36})\/launch$/.exec(path);
    const action = benchmark ? 'benchmarks.launch' : actions[path];
    if (!action) return Promise.reject(new Error('This action has no reviewed paid-run scope.'));
    // Freeze the exact JSON body independently from a still-editable form.
    const snapshot = JSON.parse(JSON.stringify(payload)) as Record<string, unknown>;
    return new Promise((resolve, reject) => {
        const event = new CustomEvent<PaidRunRequest>('lab:paid-run-request', {
            cancelable: true, detail: { action, resource: benchmark?.[1] ?? 'lab:agent', payload: snapshot, resolve, reject },
        });
        if (window.dispatchEvent(event)) reject(new Error('The human approval surface is unavailable. Reload the Lab.'));
    });
}

export async function paidFetch(path: string, options: RequestInit): Promise<Response> {
    if (options.method?.toUpperCase() !== 'POST' || typeof options.body !== 'string') {
        throw new Error('Paid requests require an exact JSON POST body.');
    }
    const snapshot = options.body;
    const payload: unknown = JSON.parse(snapshot);
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('Invalid paid request payload.');
    if (options.signal?.aborted) throw new Error('The paid request was cancelled.');
    const id = await requestPaidRun(path, payload as Record<string, unknown>);
    if (options.signal?.aborted) throw new Error('The paid request was cancelled before launch. Revoke its unused GO on the Owner page.');
    const headers = new Headers(options.headers);
    headers.set('X-Lab-Run-Id', id);

    return fetch(path, { ...options, body: snapshot, headers });
}
