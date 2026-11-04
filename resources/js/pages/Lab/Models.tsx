import { router } from '@inertiajs/react';
import { Button, Checkbox } from '@particle-academy/react-fancy';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import '../../../css/lab-models.css';

type Rate = { context: string; input: number; output: number; cache_read: number | null; cache_write_5m: number | null; cache_write_1h: number | null };
type Price = { input: number; rates: Rate[]; currency: 'USD'; unit: 'million_tokens'; checked_on: string; source: string; stale: boolean };
type Model = { key: string; id: string; label: string; kind: 'other'; created_at: string | number | null; price: Price | null };
type Provider = { key: string; label: string; configured: boolean; status: 'ready' | 'unconfigured' | 'unreachable' | 'unsupported'; message: string | null; checked_at: string | null; scope: string | null; models: Model[] };
type Props = { providers: Provider[]; allowed: string[]; pricing: { checked_on: string; stale: boolean }; errors?: Record<string, string> };

const money = (value: number | null) => value === null ? 'Not listed' : `$${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(value)}`;
const checkedAt = (value: string | null) => value === null ? 'Not checked' : `${new Date(value).toISOString().slice(0, 16).replace('T', ' ')} UTC`;
const columns: [keyof Omit<Rate, 'context'>, string][] = [['input', 'Input'], ['output', 'Output'], ['cache_read', 'Cache read'], ['cache_write_5m', 'Cache write · 5m'], ['cache_write_1h', 'Cache write · 1h']];

export default function Models({ providers, allowed, pricing, errors = {} }: Props) {
    const [selected, setSelected] = useState<string[]>(allowed);
    const [saving, setSaving] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const dirty = selected.length !== allowed.length || selected.some(key => !allowed.includes(key));
    const total = providers.reduce((count, provider) => count + provider.models.length, 0);

    const save = () => router.post('/lab/models', { allowed: selected }, {
        preserveScroll: true,
        onStart: () => setSaving(true),
        onSuccess: page => setSelected(page.props.allowed as string[]),
        onFinish: () => setSaving(false),
    });

    const refresh = () => router.post('/lab/models/refresh', {}, {
        preserveScroll: true,
        onStart: () => setRefreshing(true),
        onSuccess: page => {
            const next = page.props as unknown as Props;
            const available = new Set(next.providers.flatMap(provider => provider.models.map(model => model.key)));
            setSelected(current => dirty ? current.filter(key => available.has(key)) : next.allowed);
        },
        onFinish: () => setRefreshing(false),
    });

    return <LabShell title="Models" current="/lab/models" eyebrow="Provider models">
        <div className="lab-page-heading lab-models-heading">
            <div>
                <h1 className="lab-title">Models and prices</h1>
                <p className="lab-lead">Choose which models may be used for benchmarks. New models stay off until you enable them and save your selection.</p>
            </div>
            <div className="lab-models-actions">
                <Button type="button" data-model-action="refresh" variant="ghost" onClick={refresh} loading={refreshing} disabled={saving || refreshing}>Refresh models</Button>
                <Button type="button" data-model-action="save" onClick={save} loading={saving} disabled={saving || refreshing || !dirty}>{saving ? 'Saving…' : dirty ? 'Save selection' : 'Saved'}</Button>
            </div>
        </div>

        <section className="lab-panel lab-models-intro" aria-label="About these listings and prices">
            <p>{total} models returned by providers. Listings are cached for ten minutes. Refresh checks them again without enabling new models or changing your saved selection.</p>
            <p>Prices are USD per one million tokens, checked {pricing.checked_on}. Provider rows show the highest input price first; unknown prices stay visible. Discounts, taxes and non-token charges may differ.</p>
            <p>A listing does not guarantee permission to run a model or support for a particular task. These APIs do not classify capabilities here, so models are marked “Other”.</p>
        </section>

        {pricing.stale && <section className="lab-fuse-tripped" role="status"><b>Prices need checking</b><span>These prices are more than thirty days old. Check the official source before enabling a model.</span></section>}
        {Object.keys(errors).length > 0 && <section className="lab-fuse-tripped" role="alert"><b>Selection could not be saved</b><span>{Object.values(errors).join(' ')}</span></section>}
        {selected.length === 0 && <section className="lab-fuse-tripped" role="status"><b>No models enabled</b><span>Benchmark launches are refused until you select a model and save.</span></section>}

        {providers.map(provider => <section key={provider.key} className="lab-panel" data-model-provider={provider.key} data-listing-state={provider.status} aria-labelledby={`models-${provider.key}`}>
            <div className="lab-panel-head lab-models-provider-head">
                <h2 id={`models-${provider.key}`}>{provider.label}</h2>
                <span>{provider.status === 'ready' ? `${provider.models.filter(model => selected.includes(model.key)).length} of ${provider.models.length} enabled` : 'Listing unavailable'}</span>
            </div>
            <div className="lab-models-provider-meta">
                <span>{checkedAt(provider.checked_at)}</span>
                {provider.scope && <p>{provider.scope}</p>}
            </div>
            {provider.message && <p className="lab-empty" role="status">{provider.message}</p>}
            {provider.status === 'ready' && provider.models.length === 0 && <p className="lab-empty">This provider returned no models.</p>}
            <ul className="lab-models-list">
                {provider.models.map(model => <li key={model.key} className="lab-models-row" data-model-key={model.key}>
                    <div className="lab-models-identity">
                        <Checkbox id={`model-${encodeURIComponent(model.key)}`} label={model.label} checked={selected.includes(model.key)} disabled={saving || refreshing} onCheckedChange={checked => setSelected(current => checked ? [...new Set([...current, model.key])] : current.filter(key => key !== model.key))} />
                        <span className="lab-models-kind">Other</span>
                        <code>{model.id}</code>
                        {model.created_at !== null && <small>Provider creation date: {model.created_at}</small>}
                    </div>
                    {model.price === null ? <p className="lab-models-unknown">Price unknown. No checked rate is recorded for this model ID.</p> : <div className="lab-models-prices">
                        {model.price.rates.map(rate => <div key={rate.context} className="lab-models-rate">
                            <p className="lab-models-context">{rate.context}</p>
                            <dl className="lab-models-price-grid">
                                {columns.map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{money(rate[key])}</dd></div>)}
                            </dl>
                        </div>)}
                        <div className="lab-models-source"><span>USD / million tokens · Checked {model.price.checked_on}{model.price.stale ? ' · Needs checking' : ''}</span><Button href={model.price.source} target="_blank" rel="noopener noreferrer" variant="ghost" size="sm">Official prices</Button></div>
                    </div>}
                </li>)}
            </ul>
        </section>)}
    </LabShell>;
}
