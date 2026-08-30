import { LabShell } from '../../components/lab-shell';

export default function Evidence() {
    return <LabShell title="Evidence" current="/lab/evidence" eyebrow="Proof-of-Working registry">
        <h1 className="lab-title">Proof before ranking.</h1>
        <p className="lab-lead">Every score must resolve to a receipt: tests, browser assertions, Human+ activity, dependency evidence, timing, spend, and the lane’s 0Learning.</p>
        <section className="lab-tool-grid">
            <div className="lab-panel lab-tool"><small>Receipt type</small><h2>Functional proof</h2><p>Acceptance checks, command results, database digests, and replayable browser assertions.</p><span>Evidence required</span></div>
            <div className="lab-panel lab-tool"><small>Receipt type</small><h2>Surface proof</h2><p>Fancy-only dependency scan plus observable Browser or Human+ mode and activity records.</p><span>Mode never inferred</span></div>
            <div className="lab-panel lab-tool"><small>Learning artifact</small><h2>0Learning</h2><p>Corrections, friction, failed approaches, and harness or Fancy gaps reported by each lane.</p><span>Attached per lane</span></div>
        </section>
    </LabShell>;
}
