import { Button, Input, Modal } from '@particle-academy/react-fancy';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import type { PaidRunRequest } from '../lib/paid-run';

type Intent = { id: string; action: string; resource: string; status: string; digest: string; cap_microusd: number; expires_at: string; payload: { input: Record<string, unknown>; acp?: { provider: string; model: string; resume_session_id: string | null }; nudge?: { terminal: string; message: string; reports: { id: string; ref: string; message: string }[] }; session?: { thread_id: string; browser_attached: boolean; human_plus_attached: boolean; effective: { provider: string; model: string; mode: string; max_steps: number; tools: string[]; voice?: { provider: string; transcribe_model: string; speak_model: string; voice: string } } } } };

export function PaidRunApproval({ children }: { children: ReactNode }) {
    const pending = useRef<PaidRunRequest | null>(null);
    const [request, setRequest] = useState<PaidRunRequest | null>(null);
    const [intent, setIntent] = useState<Intent | null>(null);
    const [cap, setCap] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const receive = (event: Event) => {
            if (!(event instanceof CustomEvent)) return;
            event.preventDefault();
            const next = (event as CustomEvent<PaidRunRequest>).detail;
            if (pending.current) {
                next.reject(new Error('Review or cancel the pending paid run first.'));
                return;
            }
            pending.current = next;
            setRequest(next); setIntent(null); setCap(''); setError(null);
        };
        window.addEventListener('lab:paid-run-request', receive);
        return () => {
            window.removeEventListener('lab:paid-run-request', receive);
            pending.current?.reject(new Error('Paid run approval was closed before launch.'));
            pending.current = null;
        };
    }, []);

    async function post(path: string, payload: Record<string, unknown>): Promise<Intent> {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(payload) });
        if (!response.ok) {
            if (response.status === 403) throw new Error('Owner access or matching run authority is required. Sign in on the Owner page and review the exact scope and remaining allocation.');
            throw new Error(response.status === 419 ? 'The owner session changed. Reload the Lab before approving.' : 'The run approval could not be recorded. Review the cap and try again.');
        }
        return response.json() as Promise<Intent>;
    }

    async function step(operation: 'prepare' | 'approve' | 'go') {
        if (!request || busy) return;
        setBusy(true); setError(null);
        try {
            if (operation === 'prepare') {
                setIntent(await post('/lab/run-intents', { action: request.action, resource: request.resource, payload: request.payload, cap_usd: cap }));
            } else if (intent && operation === 'approve') {
                setIntent(await post(`/lab/run-intents/${intent.id}/approve`, { digest: intent.digest }));
            } else if (intent && operation === 'go') {
                const ready = await post(`/lab/run-intents/${intent.id}/go`, { digest: intent.digest, cap_usd: cap, confirmation: 'GO' });
                if (ready.status !== 'ready' || ready.id !== intent.id || ready.digest !== intent.digest) throw new Error('The recorded GO does not match this run.');
                pending.current = null; setRequest(null); setIntent(null);
                request.resolve(ready.id);
            }
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'Approval failed.'); }
        finally { setBusy(false); }
    }

    async function cancel() {
        if (busy || !request) return;
        setBusy(true); setError(null);
        try {
            if (intent) await post(`/lab/run-intents/${intent.id}/revoke`, {});
            pending.current = null; setRequest(null); setIntent(null);
            request.reject(new Error('The paid run was cancelled before launch.'));
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'The intent could not be revoked.'); }
        finally { setBusy(false); }
    }

    return <>
        {children}
        <Modal open={request !== null} onClose={() => { void cancel(); }} size="lg" data-agent-off-limits="consent" aria-label="Approve paid run">
            <Modal.Header>Review a paid run</Modal.Header>
            <Modal.Body>
                <div data-agent-off-limits="payment" className="space-y-4">
                    <p className="text-sm">Review the exact request, choose its USD cap, then approve it and give GO. The logical Lab session can allocate at most $5 across runs.</p>
                    <p className="text-sm">This approval records permission and allocation. Per-call invoice limits remain a separate spending control.</p>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm"><dt>Action</dt><dd>{request?.action}</dd><dt>Resource</dt><dd className="break-all">{request?.resource}</dd></dl>
                    <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded border p-3 text-xs" aria-label="Frozen request">{JSON.stringify(intent?.payload.input ?? request?.payload ?? {}, null, 2)}</pre>
                    {intent?.payload.acp && <p className="break-all text-sm">Provider: {intent.payload.acp.provider}<br />Model: {intent.payload.acp.model}<br />Conversation: {intent.payload.acp.resume_session_id ?? 'New conversation'}</p>}
                    {intent?.payload.session && <div aria-label="Frozen conversation settings" className="space-y-2 text-sm">
                        <p className="break-all">Provider: {intent.payload.session.effective.provider}<br />Model: {intent.payload.session.effective.model}<br />Mode: {intent.payload.session.effective.mode}<br />Maximum steps: {intent.payload.session.effective.max_steps}<br />Thread: {intent.payload.session.thread_id}</p>
                        <p>Browser attached: {intent.payload.session.browser_attached ? 'Yes' : 'No'}<br />Human+ attached: {intent.payload.session.human_plus_attached ? 'Yes' : 'No'}</p>
                        <p className="break-all">Mode tools: {intent.payload.session.effective.tools.join(', ') || 'None'}</p>
                        {intent.payload.session.effective.voice && <p className="break-all" aria-label="Frozen voice models">Audio provider: {intent.payload.session.effective.voice.provider}<br />Transcription: {intent.payload.session.effective.voice.transcribe_model}<br />Speech: {intent.payload.session.effective.voice.speak_model}<br />Voice: {intent.payload.session.effective.voice.voice}</p>}
                    </div>}
                    {intent?.payload.nudge && <div aria-label="Frozen delegated reports" className="space-y-2">
                        <p className="break-all text-sm">Deliver to local terminal: {intent.payload.nudge.terminal || 'Not configured'}</p>
                        <pre aria-label="Frozen delegated message" className="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded border p-3 text-xs">{intent.payload.nudge.message}</pre>
                        {intent.payload.nudge.reports.length === 0 && <p className="text-sm">No open reports in this prepared backlog.</p>}
                    </div>}
                    <label className="block space-y-2" htmlFor="paid-run-cap"><span>USD cap for this run (up to $5)</span><Input id="paid-run-cap" name="cap_usd" inputMode="decimal" value={cap} onChange={event => setCap(event.target.value)} disabled={busy || intent !== null} maxLength={16} /></label>
                    {intent && <p className="break-all text-xs">Frozen digest: {intent.digest}<br />Expires: {intent.expires_at}</p>}
                    {error && <p role="alert">{error} <a href="/lab/owner">Owner access</a></p>}
                </div>
            </Modal.Body>
            <Modal.Footer>
                <Button type="button" onClick={() => { void cancel(); }} disabled={busy}>Cancel run</Button>
                {!intent && <Button id="paid-run-prepare" type="button" onClick={() => { void step('prepare'); }} disabled={busy || !cap}>Prepare exact intent</Button>}
                {intent?.status === 'draft' && <Button id="paid-run-approve" type="button" onClick={() => { void step('approve'); }} disabled={busy}>Approve this scope and cap</Button>}
                {intent?.status === 'approved' && <Button id="paid-run-go" type="button" onClick={() => { void step('go'); }} disabled={busy}>GO — launch this run</Button>}
            </Modal.Footer>
        </Modal>
    </>;
}
