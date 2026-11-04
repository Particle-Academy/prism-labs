import { router } from '@inertiajs/react';
import { AccordionPanel, Badge, Button, Checkbox, Input, Select } from '@particle-academy/react-fancy';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import '../../../css/lab-models.css';

type Rate = { context: string; input: number; output: number; cache_read: number | null; cache_write_5m: number | null; cache_write_1h: number | null; cache_write_30m?: number | null; cache_write_12h?: number | null };
type Price = { input: number; rates: Rate[]; currency: 'USD'; unit: 'million_tokens'; checked_on: string; source: string; stale: boolean; note?: string };
type Model = { key: string; id: string; label: string; kind: 'other'; created_at: string | number | null; price: Price | null; lifecycle: { label: string; checked_on: string; source: string } | null };
type Provider = { key: string; label: string; configured: boolean; status: 'ready' | 'unconfigured' | 'unreachable' | 'unsupported' | 'authentication_rejected'; message: string | null; checked_at: string | null; scope: string | null; models: Model[] };
type Unavailable = { key: string; provider: string; id: string; reason: 'withdrawn' | 'unavailable' };
type Props = { providers: Provider[]; allowed: string[]; selection: string[]; unavailable: Unavailable[]; pricing: { checked_on: string; stale: boolean }; errors?: Record<string, string> };

const money = (value: number | null) => value === null ? 'Not listed' : `$${new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(value)}`;
const checkedAt = (value: string | null) => value === null ? 'Not checked' : `${new Date(value).toISOString().slice(0, 16).replace('T', ' ')} UTC`;
const createdDate = (value: string | number) => {
    const date = new Date(typeof value === 'number' ? value * 1000 : value);
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toISOString().slice(0, 10);
};
const columns: [keyof Omit<Rate, 'context'>, string][] = [['input', 'Input'], ['output', 'Output'], ['cache_read', 'Cache read'], ['cache_write_5m', 'Cache write · 5m'], ['cache_write_1h', 'Cache write · 1h'], ['cache_write_30m', 'Cache write · 30m'], ['cache_write_12h', 'Cache write · 12h']];

export default function Models({ providers, allowed, selection, unavailable, pricing, errors = {} }: Props) {
    const [selected, setSelected] = useState<string[]>(selection);
    const [saving, setSaving] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [filter, setFilter] = useState('');
    const [jump, setJump] = useState('');
    const [openProviders, setOpenProviders] = useState<string[]>([]);
    const [openUnknown, setOpenUnknown] = useState<string[]>([]);
    const dirty = selected.length !== selection.length || selected.some(key => !selection.includes(key));
    const total = providers.reduce((count, provider) => count + provider.models.length, 0);
    const query = filter.trim().toLowerCase();
    const savedUnavailable = (provider: Provider) => unavailable.filter(model => model.provider === provider.key && selected.includes(model.key));
    const needsAttention = (provider: Provider) => (provider.status !== 'ready' && provider.status !== 'unconfigured') || savedUnavailable(provider).length > 0;
    const compact = (provider: Provider) => provider.status === 'unconfigured' && provider.models.length === 0 && savedUnavailable(provider).length === 0;
    const matches = (model: Model) => query === '' || model.id.toLowerCase().includes(query);
    const ordered = [...providers].sort((a, b) => Number(b.models.length > 0) - Number(a.models.length > 0));
    const visible = ordered.filter(provider => !compact(provider) && (query === '' || needsAttention(provider) || provider.models.some(matches)));
    const matching = providers.reduce((count, provider) => count + provider.models.filter(matches).length, 0);

    const filterModels = (value: string) => {
        setFilter(value);
        const next = value.trim().toLowerCase();
        setOpenProviders(next === '' ? [] : providers.filter(provider => provider.models.some(model => model.id.toLowerCase().includes(next))).map(provider => provider.key));
    };

    const jumpTo = (key: string) => {
        if (!providers.some(provider => provider.key === key)) return;
        setJump(key);
        setFilter('');
        setOpenProviders([key]);
        requestAnimationFrame(() => document.getElementById(`model-provider-${key}`)?.scrollIntoView({ block: 'start', behavior: 'smooth' }));
    };

    const save = () => router.post('/lab/models', { allowed: selected }, {
        preserveScroll: true,
        onStart: () => setSaving(true),
        onSuccess: page => setSelected(page.props.selection as string[]),
        onFinish: () => setSaving(false),
    });

    const refresh = () => router.post('/lab/models/refresh', {}, {
        preserveScroll: true,
        onStart: () => setRefreshing(true),
        onSuccess: page => {
            const next = page.props as unknown as Props;
            const retained = new Set([...next.selection, ...next.providers.flatMap(provider => provider.models.map(model => model.key))]);
            setSelected(current => dirty ? current.filter(key => retained.has(key)) : next.selection);
        },
        onFinish: () => setRefreshing(false),
    });

    const renderModel = (model: Model) => <li key={model.key} className="lab-models-row" data-model-key={model.key}>
        <div className="lab-models-identity">
            <Checkbox id={`model-${encodeURIComponent(model.key)}`} label={model.label} checked={selected.includes(model.key)} disabled={saving || refreshing} onCheckedChange={checked => setSelected(current => checked ? [...new Set([...current, model.key])] : current.filter(key => key !== model.key))} />
            {model.lifecycle && <Badge color="amber" variant="soft" size="sm">{model.lifecycle.label}</Badge>}
            <code>{model.id}</code>
            {model.created_at !== null && <small>Provider creation date: {createdDate(model.created_at)}</small>}
            {model.lifecycle && <div className="lab-models-source"><span>Status checked {model.lifecycle.checked_on}</span><Button href={model.lifecycle.source} target="_blank" rel="noopener noreferrer" variant="ghost" size="sm">Model status source</Button></div>}
        </div>
        {model.price === null ? <p className="lab-models-unknown">Price unknown. No checked rate is recorded for this model ID.</p> : <div className="lab-models-prices">
            {model.price.rates.map(rate => <div key={rate.context} className="lab-models-rate">
                <p className="lab-models-context">{rate.context}</p>
                <dl className="lab-models-price-grid">
                    {columns.filter(([key]) => !key.startsWith('cache_write_') || model.price!.rates.some(quoted => quoted[key] != null)).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{money(rate[key] ?? null)}</dd></div>)}
                </dl>
            </div>)}
            {model.price.note && <p className="lab-models-unknown">{model.price.note}</p>}
            <div className="lab-models-source"><span>USD / million tokens · Checked {model.price.checked_on}{model.price.stale ? ' · Needs checking' : ''}</span><Button href={model.price.source} target="_blank" rel="noopener noreferrer" variant="ghost" size="sm">Official prices</Button></div>
        </div>}
    </li>;

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
            <p>{total} models returned by providers. Successful listings are cached for ten minutes; unavailable listings are retried after one minute. Refresh checks them again without enabling new models or changing your saved selection.</p>
            <p>Prices are USD per one million tokens, checked {pricing.checked_on}. Priced or selected models appear first. Other models are behind an unpriced-model count; filter by an ID to find them directly. Discounts, taxes and non-token charges may differ.</p>
            <p>A listing does not guarantee permission to run a model or support for a particular task. These APIs do not classify capabilities here; none are guessed from model names.</p>
        </section>

        <section className="lab-panel lab-models-navigation" aria-label="Find provider models">
            <Select id="models-provider-jump" label="Jump to provider" list={ordered.map(provider => ({ value: provider.key, label: `${provider.label} · ${provider.models.length} models` }))} placeholder="Choose a provider" value={jump} onValueChange={jumpTo} />
            <Input id="models-filter" type="search" label="Filter by model ID" placeholder="For example, gpt-6.1 or claude-sonnet" value={filter} onValueChange={filterModels} />
            <p className="lab-models-filter-result" role="status">{query === '' ? `${total} models. Expand a provider to browse its list.` : `${matching} matching model IDs. Saved selections stay unchanged.`}</p>
        </section>

        {pricing.stale && <section className="lab-fuse-tripped" role="status"><b>Prices need checking</b><span>These prices are more than thirty days old. Check the official source before enabling a model.</span></section>}
        {Object.keys(errors).length > 0 && <section className="lab-fuse-tripped" role="alert"><b>Selection could not be saved</b><span>{Object.values(errors).join(' ')}</span></section>}
        {allowed.length === 0 && <section className="lab-fuse-tripped" role="status"><b>No available models enabled</b><span>Benchmark launches are refused. Saved selections for unavailable providers are kept.</span></section>}

        <AccordionPanel orientation="vertical" value={openProviders} onValueChange={setOpenProviders} className="lab-models-providers">
        {visible.map(provider => {
            const attention = needsAttention(provider);
            const primary = provider.models.filter(model => matches(model) && (model.price !== null || selected.includes(model.key)));
            const unknown = provider.models.filter(model => matches(model) && model.price === null && !selected.includes(model.key));
            const showingUnknown = query !== '' || openUnknown.includes(provider.key);
            return <section key={provider.key} id={`model-provider-${provider.key}`} className="lab-panel lab-models-provider" data-model-provider={provider.key} data-listing-state={provider.status} aria-labelledby={`models-${provider.key}`}>
            <AccordionPanel.Section id={provider.key} pinned={attention} unstyled className="lab-models-provider-section">
            <div className="lab-panel-head lab-models-provider-head">
                {attention ? <h2 id={`models-${provider.key}`}>{provider.label}</h2> : <AccordionPanel.Trigger>{({ open, toggle }) => <h2 id={`models-${provider.key}`}><Button type="button" className="lab-models-provider-toggle" data-model-provider-toggle={provider.key} variant="ghost" onClick={toggle} aria-expanded={open} aria-controls={`models-content-${provider.key}`}><span className="lab-models-provider-name">{provider.label}</span><span className="lab-models-provider-action">{open ? 'Hide models' : 'Show models'}</span></Button></h2>}</AccordionPanel.Trigger>}
                <span>{provider.status === 'ready' ? `${provider.models.filter(model => selected.includes(model.key)).length} of ${provider.models.length} enabled · ${provider.models.filter(model => model.price !== null).length} priced` : 'Listing needs attention'}</span>
            </div>
            <div id={`models-content-${provider.key}`}><AccordionPanel.Content unstyled>
            <div className="lab-models-provider-meta">
                <span>{checkedAt(provider.checked_at)}</span>
                {provider.scope && <p>{provider.scope}</p>}
            </div>
            {provider.message && <p className="lab-empty" role="status">{provider.message}</p>}
            {provider.status === 'ready' && provider.models.length === 0 && <p className="lab-empty">This provider returned no models.</p>}
            <ul className="lab-models-list">
                {primary.map(renderModel)}
                {unavailable.filter(model => model.provider === provider.key && selected.includes(model.key)).map(model => <li key={model.key} className="lab-models-row" data-unavailable-key={model.key}>
                    <div className="lab-models-identity"><Checkbox id={`unavailable-${encodeURIComponent(model.key)}`} label={model.id} checked disabled /><Badge color="amber" variant="soft" size="sm">{model.reason === 'withdrawn' ? 'No longer offered' : 'Unavailable'}</Badge><code>{model.id}</code></div>
                    <div className="lab-models-unknown">{model.reason === 'withdrawn' ? <><p>This provider no longer offers this saved model. It cannot start a benchmark.</p><Button type="button" variant="ghost" disabled={saving || refreshing} onClick={() => setSelected(current => current.filter(key => key !== model.key))}>Remove saved model</Button></> : <p>Your selection is kept while this provider’s listing is unavailable. Saving another provider does not remove it. This model cannot start a benchmark until its listing is available.</p>}</div>
                </li>)}
            </ul>
            {unknown.length > 0 && <div className="lab-models-unknown-group">
                {query === '' && <Button type="button" variant="ghost" className="lab-models-unknown-toggle" data-model-unknown-toggle={provider.key} aria-expanded={showingUnknown} aria-controls={`models-unknown-${provider.key}`} onClick={() => setOpenUnknown(current => current.includes(provider.key) ? current.filter(key => key !== provider.key) : [...current, provider.key])}>{showingUnknown ? 'Hide' : 'Show'} {unknown.length} more models with unknown prices</Button>}
                <ul id={`models-unknown-${provider.key}`} className="lab-models-list">{showingUnknown && unknown.map(renderModel)}</ul>
            </div>}
            </AccordionPanel.Content></div>
            </AccordionPanel.Section>
        </section>;
        })}
        </AccordionPanel>
        {query === '' && ordered.some(compact) && <section className="lab-panel lab-models-keyless"><h2>Providers without configuration</h2><ul>{ordered.filter(compact).map(provider => <li key={provider.key} id={`model-provider-${provider.key}`} data-model-provider={provider.key} data-listing-state={provider.status}><b>{provider.label}</b><span>{provider.message}</span></li>)}</ul></section>}
        {unavailable.filter(model => !providers.some(provider => provider.key === model.provider)).map(model => <section key={model.key} className="lab-panel lab-models-intro"><p>Saved selection {model.key} is kept but unavailable: its provider is not configured in this Lab.</p></section>)}
    </LabShell>;
}
