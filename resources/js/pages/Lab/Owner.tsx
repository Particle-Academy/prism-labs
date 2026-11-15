import { Button, Input } from '@particle-academy/react-fancy';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import { RunAllocations } from '../../components/run-allocations';
import type { RunAllocationSnapshot } from '../../components/run-allocations';

type Props = {
    configured: boolean;
    isOwner: boolean;
    csrfToken: string;
    errors?: Record<string, string>;
    allocations?: RunAllocationSnapshot | null;
};

export default function Owner({ configured, isOwner, csrfToken, errors = {}, allocations = null }: Props) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    return (
        <LabShell title="Lab owner" current="/lab/owner" eyebrow="Human approval">
            <section className="mx-auto max-w-lg space-y-5 rounded-xl border p-6" style={{ borderColor: 'var(--k-hairline)', background: 'var(--k-bg-1)' }}>
                <h1 className="text-2xl font-semibold">Lab owner</h1>
                <p className="text-sm leading-6">Signing in identifies you for approvals. Each paid run also needs its exact scope, budget and GO.</p>
                {!configured ? (
                    <p role="status">Owner access is not configured. The operator must provision the human account before approvals are available.</p>
                ) : isOwner ? (
                    <>
                        <p role="status">You are signed in as the configured Lab owner.</p>
                        <form method="post" action="/lab/owner/logout" data-agent-off-limits="auth">
                            <input type="hidden" name="_token" value={csrfToken} />
                            <Button type="submit">Sign out</Button>
                        </form>
                    </>
                ) : (
                    // Native navigation reloads the rotated session's CSRF meta
                    // token, which the other Lab forms read on each submission.
                    <form method="post" action="/lab/owner/login" data-agent-off-limits="auth" className="space-y-4">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <label className="block space-y-2" htmlFor="owner-email">
                            <span>Email</span>
                            <Input id="owner-email" name="email" type="email" autoComplete="username" required maxLength={254} value={email} onChange={(event) => setEmail(event.target.value)} />
                        </label>
                        <label className="block space-y-2" htmlFor="owner-password">
                            <span>Password</span>
                            <Input id="owner-password" name="password" type="password" autoComplete="current-password" required maxLength={4096} value={password} onChange={(event) => setPassword(event.target.value)} />
                        </label>
                        {(errors.email || errors.password) && <p role="alert">The owner credentials could not be verified.</p>}
                        <Button id="owner-login-submit" type="submit">Sign in</Button>
                    </form>
                )}
            </section>
            {isOwner && allocations && <RunAllocations initial={allocations} csrfToken={csrfToken} />}
        </LabShell>
    );
}
