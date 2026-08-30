import { LabShell } from '../../components/lab-shell';

const tools = [
    ['/lab/telemetry', 'Telemetry ledger', 'Daily and monthly token burn, cost provenance, and operation traces.'],
    ['/lab/tests', 'Provider matrix', 'Raw provider capability and conformance probes.'],
    ['/lab/threads', 'Harness threads', 'Inspect persisted durable conversations and reconstructed messages.'],
    ['/lab/team', 'Parity team', 'Reachability, delegation, and direct team communications.'],
    ['/lab/human-plus-fixture', 'Human+ fixture', 'Controlled browser surface for bridge and staged-write verification.'],
] as const;

export default function Diagnostics() {
    return <LabShell title="Diagnostics" current="/lab/diagnostics" eyebrow="Secondary tools">
        <h1 className="lab-title">Diagnostics, not the product shell.</h1>
        <p className="lab-lead">The original OTel test surfaces still exist. They now support the workflows instead of defining the Lab.</p>
        <section className="lab-tool-grid">{tools.map(([href, title, text]) => <a href={href} className="lab-panel lab-tool" key={href}><small>Diagnostic</small><h2>{title}</h2><p>{text}</p><span>Open →</span></a>)}</section>
    </LabShell>;
}
