import { Badge, Button, Callout, Card, Checkbox, CodeBlock, Grid, Heading, Input, Select, Stat, Text } from '@particle-academy/react-fancy';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { LabShell } from '../../components/lab-shell';
import { labBlurb } from '../../components/lab-nav';
import { requestCliRun } from '../../lib/paid-run';

type Agent = { package: string; version: string; pretty: string; reference: string | null; source_digest: string };

type Scenario = {
    id: string; version: number; title: string; origin: string; brief: string; digest: string; judge: string;
    store: { saved: string | null; draft: string | null }; checks: string[];
};

type Check = { check: string; label: string; passed: boolean; detail: string };

type Result = {
    scenario: string; passed: boolean; structuralPassed: boolean; outcome: string | null; message: string | null;
    judgeVerdict: string | null; judgeReason: string | null; error: string | null; modelCalls: number;
    checks: Check[]; artefacts: string[];
};

type Run = {
    id: string; lane: 'scripted' | 'live'; selection: string; status: string; passed: boolean | null;
    model: string | null; judgeModel: string | null; agentVersion: string | null; sourceDigest: string | null;
    costMicrousd: number | null; error: string | null; startedAt: string; finishedAt: string | null;
    certifiable: boolean; results: Result[];
};

type Certificate = { sequence: number; version: string; hash: string; previous: string | null; run: string | null; issuedAt: string };

type Props = {
    agent: Agent | null; agentError: string | null;
    defaults: { model: string; maxOutputTokens: number };
    scenarios: Scenario[]; runs: Run[]; certificates: Certificate[]; chainProblems: string[];
};

const STATUS_TONE: Record<string, 'green' | 'red' | 'amber' | 'zinc'> = { passed: 'green', failed: 'red', stopped: 'amber', running: 'zinc' };

function usd(micro: number | null): string {
    return micro === null ? 'free' : `$${(micro / 1_000_000).toFixed(4)}`;
}

function ResultCard({ run, result }: { run: Run; result: Result }) {
    const [open, setOpen] = useState(!result.passed);
    return (
        <Card variant="outlined" padding="sm" data-testid={`docs-result-${run.id}-${result.scenario}`} data-passed={result.passed ? 'yes' : 'no'}>
            <Card.Body className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Text weight="semibold" className="font-mono">{result.scenario}</Text>
                    <div className="flex flex-wrap gap-1">
                        <Badge color={result.passed ? 'green' : 'red'} variant="soft" size="sm" dot>{result.passed ? 'pass' : 'fail'}</Badge>
                        <Badge color={result.structuralPassed ? 'green' : 'red'} variant="outline" size="sm">structural</Badge>
                        {result.judgeVerdict !== null && <Badge color={result.judgeVerdict === 'pass' ? 'green' : 'red'} variant="outline" size="sm">judge {result.judgeVerdict}</Badge>}
                    </div>
                </div>
                <Text size="xs" color="muted">outcome {result.outcome ?? 'none'} · {result.modelCalls} model call(s){result.error ? ` · ${result.error}` : ''}</Text>
                {result.message && <Text size="sm">“{result.message}”</Text>}
                {result.judgeReason && <Text size="xs" color="muted">Judge: {result.judgeReason}</Text>}
                <Button variant="ghost" size="sm" aria-expanded={open} onClick={() => setOpen(v => !v)}>{open ? 'Hide checks' : `Show ${result.checks.length} checks`}</Button>
                {open && (
                    <div className="space-y-1">
                        {result.checks.map((check, i) => (
                            <div key={i} className="flex items-start gap-2">
                                <Badge color={check.passed ? 'green' : 'red'} variant="soft" size="sm">{check.passed ? '✓' : '✗'}</Badge>
                                <Text size="xs" className="break-all"><span className="font-mono">{check.label}</span> · {check.detail}</Text>
                            </div>
                        ))}
                    </div>
                )}
                {result.artefacts.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {result.artefacts.map(name => (
                            <Button key={name} size="sm" variant="ghost" onClick={() => { window.location.href = `/lab/docs/runs/${run.id}/${result.scenario}/${name}`; }}>{name}</Button>
                        ))}
                    </div>
                )}
            </Card.Body>
        </Card>
    );
}

function LivePrepare({ scenarios, defaults }: { scenarios: Scenario[]; defaults: Props['defaults'] }) {
    const [scenario, setScenario] = useState('all');
    const [model, setModel] = useState(defaults.model);
    const [judge, setJudge] = useState(true);
    const [command, setCommand] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function prepare() {
        setBusy(true); setError(null); setCommand(null);
        try {
            // Exactly the four keys lab:docs-run freezes; the server re-derives
            // the full plan (scenario digests, prices, agent bytes) from them.
            const id = await requestCliRun('cli.docs.run', { scenario, model, judge, judge_model: model });
            setCommand(`php artisan lab:docs-run --lane=live --scenario=${scenario} --model=${model}${judge ? ' --judge' : ''} --approved-run=${id}`);
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'The approval was not completed.');
        } finally { setBusy(false); }
    }

    return (
        <Card data-testid="docs-live-prepare">
            <Card.Header><Heading as="h2" size="md">Live lane (paid)</Heading></Card.Header>
            <Card.Body className="space-y-3">
                <Text size="sm">A real provider model edits each scenario's document. Every model call is reserved against the USD cap you approve, before it is sent. One approval buys one run; it expires 15 minutes after you prepare it.</Text>
                <Grid cols={3} gap="md" responsive>
                    <Select id="docs-live-scenario" label="Scenarios" list={[{ value: 'all', label: 'All scenarios' }, ...scenarios.map(s => ({ value: s.id, label: `${s.id}: ${s.title}` }))]} value={scenario} onValueChange={v => setScenario(String(v))} />
                    <Input id="docs-live-model" label="Builder model (anthropic)" value={model} onChange={event => setModel(event.target.value)} maxLength={100} />
                    <Checkbox id="docs-live-judge" label="Also run the model judge (same approval and cap)" checked={judge} onCheckedChange={checked => setJudge(Boolean(checked))} />
                </Grid>
                <Button id="docs-live-approve" onClick={() => { void prepare(); }} disabled={busy || model === ''}>Approve a live run…</Button>
                {error && <Callout color="red">{error}</Callout>}
                {command && (
                    <div className="space-y-2" data-testid="docs-live-command">
                        <Text size="sm">Approved. Run this from the prism-labs folder; the run appears below when it finishes.</Text>
                        <CodeBlock code={command} language="sh" />
                    </div>
                )}
            </Card.Body>
        </Card>
    );
}

export default function DocsLab({ agent, agentError, defaults, scenarios, runs, certificates, chainProblems }: Props) {
    const [selection, setSelection] = useState('all');
    const [busy, setBusy] = useState(false);
    const runScripted = () => {
        setBusy(true);
        router.post('/lab/docs/scripted', { scenario: selection }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <LabShell title="Docs agent lab" current="/lab/docs" eyebrow="G-316">
            <Text color="muted" className="mb-6">{labBlurb('/lab/docs')}</Text>

            {agentError && <Callout color="red" className="mb-6">{agentError}</Callout>}

            <Grid cols={3} gap="md" responsive className="mb-8">
                <Stat value={agent ? agent.version : 'unknown'} label={agent ? `prism-agent-docs (${agent.pretty})` : 'prism-agent-docs'} size="lg" />
                <Stat value={agent ? agent.source_digest.slice(0, 12) : 'unknown'} label="installed source digest" size="lg" />
                <Stat value={String(scenarios.length)} label="scenarios" size="lg" />
            </Grid>

            <Card className="mb-8" data-testid="docs-scenarios">
                <Card.Header><Heading as="h2" size="md">Scenarios</Heading></Card.Header>
                <Card.Body className="space-y-4">
                    {scenarios.map(s => (
                        <div key={s.id} className="space-y-1" data-testid={`docs-scenario-${s.id}`}>
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge color="indigo" variant="soft">{s.id} v{s.version}</Badge>
                                <Text weight="semibold">{s.title}</Text>
                                <Text size="xs" color="muted" className="font-mono">{s.digest.slice(0, 12)}</Text>
                            </div>
                            <Text size="xs" color="muted">{s.origin} · saved: {s.store.saved ?? 'none'} · pending draft: {s.store.draft ?? 'none'}</Text>
                            <Text size="sm">Ask: “{s.brief}”</Text>
                            <Text size="xs" color="muted">Checks: {s.checks.join(' · ')}</Text>
                        </div>
                    ))}
                </Card.Body>
            </Card>

            <Grid cols={2} gap="md" responsive className="mb-8">
                <Card data-testid="docs-scripted">
                    <Card.Header><Heading as="h2" size="md">Scripted lane (free)</Heading></Card.Header>
                    <Card.Body className="space-y-3">
                        <Text size="sm">Prism's fake plays each scenario's realistic tool calls against the agent's real tools; last-word writes the .docx and the lab re-reads it. No network, no model, no cost. CI runs it on every pull request.</Text>
                        <Select id="docs-scripted-scenario" label="Scenarios" list={[{ value: 'all', label: 'All scenarios' }, ...scenarios.map(s => ({ value: s.id, label: s.id }))]} value={selection} onValueChange={v => setSelection(String(v))} />
                        <Button id="docs-scripted-run" onClick={runScripted} disabled={busy}>{busy ? 'Running…' : 'Run the scripted lane'}</Button>
                        <CodeBlock code={`php artisan lab:docs-run --lane=scripted --scenario=${selection}`} language="sh" />
                    </Card.Body>
                </Card>
                <LivePrepare scenarios={scenarios} defaults={defaults} />
            </Grid>

            <Heading as="h2" size="md" className="mb-3">Runs</Heading>
            {runs.length === 0 && <Text color="muted" className="mb-8">No runs yet.</Text>}
            <div className="mb-8 space-y-4">
                {runs.map(run => (
                    <Card key={run.id} data-testid={`docs-run-${run.id}`} data-status={run.status}>
                        <Card.Header>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge color={run.lane === 'live' ? 'violet' : 'slate'} variant="soft">{run.lane}</Badge>
                                    <Badge color={STATUS_TONE[run.status] ?? 'zinc'} variant="soft" dot>{run.status}</Badge>
                                    <Text size="sm" className="font-mono">{run.id.slice(-8)}</Text>
                                    {run.certifiable && <Badge color="green" variant="outline">certifiable</Badge>}
                                </div>
                                <Text size="xs" color="muted">{run.startedAt} · agent {run.agentVersion ?? '?'} ({(run.sourceDigest ?? '').slice(0, 12)}){run.model ? ` · ${run.model}` : ''}{run.judgeModel ? ` · judge ${run.judgeModel}` : ''} · {usd(run.costMicrousd)}{run.error ? ` · ${run.error}` : ''}</Text>
                            </div>
                        </Card.Header>
                        <Card.Body>
                            <Grid cols={3} gap="sm" responsive>
                                {run.results.map(result => <ResultCard key={result.scenario} run={run} result={result} />)}
                            </Grid>
                        </Card.Body>
                    </Card>
                ))}
            </div>

            <Card data-testid="docs-certificates">
                <Card.Header>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading as="h2" size="md">Certificates</Heading>
                        <Badge color={chainProblems.length === 0 ? 'green' : 'red'} variant="soft" dot>{chainProblems.length === 0 ? 'chain intact' : 'chain broken'}</Badge>
                    </div>
                </Card.Header>
                <Card.Body className="space-y-2">
                    {chainProblems.map((problem, i) => <Callout key={i} color="red">{problem}</Callout>)}
                    {certificates.length === 0 && <Text color="muted" size="sm">None issued. A certificate needs a live run that passed every scenario, structurally and by the judge: <span className="font-mono">php artisan lab:docs-certify</span>.</Text>}
                    {certificates.map(c => (
                        <Text key={c.sequence} size="xs" className="break-all font-mono">#{c.sequence} prism-agent-docs {c.version} · run {(c.run ?? '').slice(-8)} · {c.issuedAt} · {c.hash}</Text>
                    ))}
                </Card.Body>
            </Card>
        </LabShell>
    );
}
