import { router } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type Model = { key: string; provider: string; provider_label: string; id: string; label: string; tier: string; configured: boolean };

export default function Models({ models, allowed }: { models: Model[]; allowed: string[] }) {
    const [selected, setSelected] = useState<string[]>(allowed);
    const [saving, setSaving] = useState(false);

    const toggle = (key: string) => setSelected(current => current.includes(key) ? current.filter(item => item !== key) : [...current, key]);

    const save = () => {
        setSaving(true);
        router.post('/lab/models', { allowed: selected }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    // Grouped by provider so the list reads the way the decision is made —
    // "which Anthropic models may we spend on" — rather than as one flat list
    // where two providers' naming conventions sit side by side.
    const providers = [...new Set(models.map(model => model.provider))];
    const dirty = selected.length !== allowed.length || selected.some(key => !allowed.includes(key));

    return <LabShell title="Models" current="/lab/models" eyebrow="Benchmark spend policy">
        <div className="lab-page-heading">
            <div>
                <h1 className="lab-title">Which models may a lane use?</h1>
                <p className="lab-lead">A benchmark spec is frozen when it is approved, so a model chosen here is one that every run of that spec pays for until somebody cuts a new revision. Unticked models are refused before a lane starts.</p>
            </div>
            <div className="lab-run-actions">
                <button type="button" className="k-btn k-btn--primary" onClick={save} disabled={saving || !dirty}>
                    {saving ? 'Saving…' : dirty ? 'Save selection' : 'Saved'}
                </button>
            </div>
        </div>

        {selected.length === 0 && <section className="lab-fuse-tripped">
            <b>No models enabled</b>
            <span>Every benchmark launch will be refused until at least one is ticked. That is a legitimate way to stop spend without deleting specs.</span>
        </section>}

        {providers.map(provider => {
            const rows = models.filter(model => model.provider === provider);
            const configured = rows[0]?.configured ?? false;
            return <section key={provider} className="lab-panel">
                <div className="lab-panel-head">
                    <span>{rows[0]?.provider_label ?? provider}</span>
                    <span>{configured ? `${rows.filter(r => selected.includes(r.key)).length} of ${rows.length} enabled` : 'no API key configured'}</span>
                </div>
                {!configured && <p className="lab-empty">This provider has no credential, so its models cannot run even when ticked. They are listed rather than hidden, because hiding them would read as "that model does not exist".</p>}
                <ul className="lab-model-list">
                    {rows.map(model => <li key={model.key}>
                        <label>
                            <input type="checkbox" checked={selected.includes(model.key)} onChange={() => toggle(model.key)} />
                            <span className="lab-model-name">{model.label}</span>
                            <code>{model.id}</code>
                            <span className={`lab-tier is-${model.tier}`}>{model.tier}</span>
                        </label>
                    </li>)}
                </ul>
            </section>;
        })}
    </LabShell>;
}
