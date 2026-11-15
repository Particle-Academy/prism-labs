import { Button } from '@particle-academy/react-fancy';
import { useState } from 'react';

type RunAllocation = {
    id: string;
    action: string;
    status: string;
    cap_microusd: number;
    expires_at: string;
    consumed_at: string | null;
    revoked_at: string | null;
    can_revoke: boolean;
};

export type RunAllocationSnapshot = {
    allocated_microusd: number;
    available_microusd: number;
    session_limit_microusd: number;
    intents: RunAllocation[];
    next_before: string | null;
};

const usd = (micro: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 6 }).format(micro / 1_000_000);

function runStatus(intent: RunAllocation): string {
    if (intent.status === 'revoked') return intent.consumed_at ? 'Cancelled after start' : 'Cancelled';
    if (!intent.consumed_at && Date.parse(intent.expires_at) <= Date.now()) return 'Expired, unused';
    if (intent.consumed_at) return 'Work started';
    return intent.status === 'ready' ? 'GO recorded, unused' : intent.status === 'approved' ? 'Approved, awaiting GO' : 'Draft';
}

export function RunAllocations({ initial, csrfToken }: { initial: RunAllocationSnapshot; csrfToken: string }) {
    const [snapshot, setSnapshot] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    async function read(before: string | null = null): Promise<RunAllocationSnapshot> {
        const response = await fetch(`/lab/run-intents${before ? `?before=${encodeURIComponent(before)}` : ''}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('The allocation could not be loaded. Refresh the Owner page to verify your session.');
        return response.json() as Promise<RunAllocationSnapshot>;
    }

    async function cancel(id: string) {
        if (busy) return;
        setBusy(true); setError(null); setNotice(null);
        let cancelled = false;
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? csrfToken;
            const response = await fetch(`/lab/run-intents/${encodeURIComponent(id)}/revoke`, {
                method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: '{}',
            });
            if (!response.ok) throw new Error(response.status === 419 ? 'The owner session changed. Reload this page before cancelling.' : 'The run could not be cancelled. Refresh this page to verify the run and your owner session.');
            cancelled = true;
            setSnapshot(await read());
            setNotice('Run cancelled. The allocation shown below has been refreshed.');
        } catch (failure) {
            setError(cancelled ? 'Cancellation was recorded. Refresh the Owner page to load the latest allocation.' : failure instanceof Error ? failure.message : 'Cancellation could not be recorded.');
        } finally { setBusy(false); }
    }

    async function older() {
        if (busy || !snapshot.next_before) return;
        setBusy(true); setError(null); setNotice(null);
        try {
            const next = await read(snapshot.next_before);
            setSnapshot(current => ({ ...next, intents: [...current.intents, ...next.intents] }));
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'Older runs could not be loaded.'); }
        finally { setBusy(false); }
    }

    return <section aria-label="Run allocations" data-agent-off-limits="consent" className="mx-auto mt-6 max-w-3xl space-y-5 rounded-xl border p-6" style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}>
        <div className="space-y-2">
            <h2 className="text-xl font-semibold">Run allocations</h2>
            <p className="text-sm leading-6">Review prior approvals, including expired unused runs. Cancellation returns an unused allocation; allocations stay recorded once work has started.</p>
        </div>
        <dl className="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt>Allocated</dt><dd className="text-xl font-semibold" data-allocation-total>{usd(snapshot.allocated_microusd)}</dd></div>
            <div><dt>Available</dt><dd className="text-xl font-semibold" data-allocation-available>{usd(snapshot.available_microusd)}</dd></div>
            <div><dt>Session limit</dt><dd className="text-xl font-semibold">{usd(snapshot.session_limit_microusd)}</dd></div>
        </dl>
        <p className="text-sm">These amounts track approved allocations. Per-call invoice limits remain a separate spending control.</p>
        {error && <p role="alert">{error}</p>}
        {notice && <p role="status">{notice}</p>}
        {snapshot.intents.length === 0 ? <p className="text-sm">No run intents have been prepared in this Lab session.</p> : <ul className="space-y-3">
            {snapshot.intents.map(intent => <li key={intent.id} data-run-allocation={intent.id} className="space-y-3 rounded-lg border p-4" style={{ borderColor: 'var(--k-hairline)' }}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1">
                        <h3 className="break-all font-medium">{intent.action}</h3>
                        <p className="text-sm">{runStatus(intent)} · {usd(intent.cap_microusd)}</p>
                    </div>
                    {intent.can_revoke && <Button id={`cancel-allocation-${intent.id}`} type="button" disabled={busy} onClick={() => { void cancel(intent.id); }}>{intent.consumed_at ? 'Stop further work' : 'Cancel run'}</Button>}
                </div>
                <p className="break-all text-xs">Reference: {intent.id}<br />Expires: <time dateTime={intent.expires_at}>{intent.expires_at}</time></p>
            </li>)}
        </ul>}
        {snapshot.next_before && <Button id="owner-older-allocations" type="button" disabled={busy} onClick={() => { void older(); }}>Load older runs</Button>}
    </section>;
}
