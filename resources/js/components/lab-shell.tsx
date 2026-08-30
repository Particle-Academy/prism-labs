import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PLabAgentLauncher } from './plab-agent';

const primary = [
    ['/lab', 'Cockpit'],
    ['/lab/benchmarks', 'Benchmarks'],
    ['/lab/consensus', 'Consensus'],
    ['/lab/evidence', 'Evidence'],
] as const;

export function LabShell({ title, current, eyebrow, children }: { title: string; current: string; eyebrow?: string; children: ReactNode }) {
    const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

    return (
        <div className="k-page lab-shell">
            <Head title={title} />
            <LabTopbar current={current} />
            <main className="lab-content">
                {eyebrow && <p className="lab-eyebrow">{eyebrow}</p>}
                {flash?.success && <div className="lab-flash is-success" role="status">{flash.success}</div>}
                {flash?.error && <div className="lab-flash is-error" role="alert">{flash.error}</div>}
                {children}
            </main>
            <PLabAgentLauncher />
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
