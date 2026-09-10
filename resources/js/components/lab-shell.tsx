import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { OverseerLauncher } from './overseer';

const primary = [
    ['/lab', 'Cockpit'],
    ['/lab/benchmarks', 'Benchmarks'],
    ['/lab/models', 'Models'],
    ['/lab/consensus', 'Consensus'],
    ['/lab/evidence', 'Evidence'],
] as const;

export function LabShell({ title, current, eyebrow, children }: { title: string; current: string; eyebrow?: string; children: ReactNode }) {
    // `status` is read as well as `flash`, and that is a fix rather than a
    // belt-and-braces read. Every success message in this app is flashed with
    // `->with('status', ...)` — the probes, the benchmark deletes, the evidence
    // sends, the model policy saves — and this shell rendered only
    // `flash.success`, which NOTHING sets. So every one of them was dropped
    // silently: you pressed a button, the request succeeded, and the page told
    // you nothing at all.
    //
    // Shared top-level by HandleInertiaRequests because Fortify sets a session
    // `status` after things like a password-reset email. The Lab adopted the
    // same key and never wired the other end.
    const { flash, status } = usePage<{
        flash?: { success?: string | null; error?: string | null };
        status?: string | null;
    }>().props;

    const success = flash?.success ?? status;

    return (
        <div className="k-page lab-shell">
            <Head title={title} />
            <LabTopbar current={current} />
            <main className="lab-content">
                {eyebrow && <p className="lab-eyebrow">{eyebrow}</p>}
                {success && <div className="lab-flash is-success" role="status">{success}</div>}
                {flash?.error && <div className="lab-flash is-error" role="alert">{flash.error}</div>}
                {children}
            </main>
            <OverseerLauncher />
        </div>
    );
}

export function LabTopbar({ current }: { current: string }) {
    return <header className="lab-topbar">
                <Link href="/lab" className="lab-brand"><span>P</span>Prism Lab</Link>
                <nav aria-label="Lab workflows">
                    {primary.map(([href, label]) => (
                        <Link key={href} href={href} className={current === href ? 'is-active' : ''}>{label}</Link>
                    ))}
                    <Link href="/lab/diagnostics" className={current === '/lab/diagnostics' ? 'is-active' : ''}>Diagnostics</Link>
                </nav>
                <div className="lab-live"><i /> telemetry live</div>
            </header>;
}
