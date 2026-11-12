import { Head, router } from '@inertiajs/react';
import { Badge, Button, Callout, Card, CodeBlock, Heading, Tabs, Text } from '@particle-academy/react-fancy';
import { useEffect } from 'react';
import { RsiContractReview, type InstrumentPin, type RsiContract, type RsiReviewDocuments } from '../../components/rsi-contract-review';

type Costs = { calls: number; reserved_microusd: number; pending_microusd: number; accounted_charge_microusd: number; known_charge_microusd: number | null; uncertain_calls: number };
type Lane = { id: string; ordinal: number; status: string; declared: Record<string, unknown>; observed_identity: Record<string, unknown>; classification: string; judgment: 'unjudged'; metrics: Record<string, number | null>; run_id: string | null; thread_id: string | null; trace_id: null; artifact: { sha256: string | null; bytes: number | null; content_captured: false }; instruments: Array<{ assertion_id: string; instrument: InstrumentPin; status: 'pass' | 'fail' | 'unknown'; reason: string; observed: unknown }>; costs: Costs; flow: unknown };
export type RsiEvidence = { contract: RsiContract; review_documents: RsiReviewDocuments; run: { id: string; status: string; started_at: string | null; finished_at: string | null; cancelled_at: string | null; selected_lane: string | null; judgment: 'unjudged'; artifact_content_captured: false }; allocation: { intent_id: string; cap_microusd: number; revoked: boolean; expired: boolean; costs: Costs }; lanes: Lane[] };
const show = (value: unknown) => value === null || value === undefined ? 'Unknown' : typeof value === 'object' ? JSON.stringify(value) : String(value);

export default function RsiRunRoom({ evidence }: { evidence: RsiEvidence }) {
    const { run, contract, allocation, lanes } = evidence;
    const active = ['queued', 'ready', 'running'].includes(run.status);
    const selected = lanes.find(lane => lane.id === run.selected_lane);
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(() => { if (document.visibilityState === 'visible') router.reload({ only: ['evidence'] }); }, 8000);
        return () => window.clearInterval(timer);
    }, [active]);
    const select = (id: string) => router.get(`/runs/${run.id}`, { lane: id }, { preserveState: true, preserveScroll: true, only: ['evidence'] });
    return <>
        <Head title={`${contract.name} · Run room`} />
        <main className="mx-auto max-w-7xl space-y-6 p-6" data-surface="rsi-run-room" data-run-id={run.id}>
            <div className="flex flex-wrap gap-3"><Button href="/lab/benchmarks" variant="ghost">Back to Lab</Button><Button href={`/lab/benchmarks/specs/${contract.id}`} variant="ghost">Frozen contract review</Button><Button href={`/runs/${run.id}/evidence${run.selected_lane ? `?lane=${encodeURIComponent(run.selected_lane)}` : ''}`} variant="default" data-action="rsi-export">Export evidence</Button><Button variant="ghost" onClick={() => router.reload({ only: ['evidence'] })}>Refresh</Button></div>
            <Heading as="h1" size="xl">{contract.name}</Heading><div className="flex flex-wrap gap-3"><Badge>Unjudged</Badge><Badge>{run.status}</Badge><Badge>Content capture off</Badge></div>
            <Callout>{active ? 'Native lane work is in progress. This view refreshes every eight seconds while visible.' : 'This run has settled. Missing measurements remain Unknown.'} Measurements show declared expectations and observed evidence; acceptance and reviewer decisions remain unavailable.</Callout>
            {run.cancelled_at && <Callout color="red">The run was stopped. A request already in flight can finish, and its uncertain cost remains reserved.</Callout>}
            {active && <div data-agent-off-limits="consent"><Button id="rsi-stop" data-action="rsi-stop" color="red" onClick={() => { if (window.confirm('Stop this run? Queued work cannot start. Uncertain in-flight cost remains reserved.')) router.post(`/lab/benchmarks/runs/${run.id}/stop`); }}>Emergency stop</Button></div>}
            <Card><Card.Header heading="Authorized allocation" description={`Intent ${allocation.intent_id}`} /><Card.Body className="space-y-2"><Text>Cap: {allocation.cap_microusd} micro-USD · Revoked: {String(allocation.revoked)} · Expired: {String(allocation.expired)}</Text><CostSummary costs={allocation.costs} /></Card.Body></Card>
            <Heading as="h2" size="lg">Persisted lane order</Heading>
            <Tabs activeTab={run.selected_lane ?? ''} onTabChange={select} variant="underline"><Tabs.List className="overflow-x-auto">
                {lanes.map(lane => <Tabs.Tab key={lane.id} value={lane.id} data-lane-id={lane.id}>{lane.ordinal}. {show(lane.declared.id)} · {lane.status}</Tabs.Tab>)}
            </Tabs.List></Tabs>
            {selected && <div className="space-y-4" data-selected-lane={selected.id}>
                <Card><Card.Header heading={`Lane ${selected.ordinal}: ${show(selected.declared.id)}`} description={`Execution ${selected.status} · ${selected.classification}`} /><Card.Body className="space-y-2"><Text>Native run: {show(selected.run_id)} · Thread: {show(selected.thread_id)} · Trace: {show(selected.trace_id)}</Text><CostSummary costs={selected.costs} /><CodeBlock language="json" title="Native measurements" code={JSON.stringify(selected.metrics, null, 2)} wrap /></Card.Body></Card>
                <Card><Card.Header heading="Declared identity" /><Card.Body><CodeBlock language="json" code={JSON.stringify(selected.declared, null, 2)} wrap /></Card.Body></Card>
                <Card><Card.Header heading="Observed native identity" description="Unknown fields have not been measured or do not match the frozen identity." /><Card.Body><CodeBlock language="json" code={JSON.stringify(selected.observed_identity, null, 2)} wrap /></Card.Body></Card>
                <Card><Card.Header heading="Artifact reference" description="Raw output and transcript are not captured in this report." /><Card.Body><Text className="break-all">SHA256: {show(selected.artifact.sha256)}</Text><Text>Bytes: {show(selected.artifact.bytes)}</Text></Card.Body></Card>
                <Card><Card.Header heading="Deterministic instruments" /><Card.Body className="space-y-3">{selected.instruments.map(item => <Card key={item.assertion_id} data-assertion-id={item.assertion_id}><Card.Body><Heading as="h3" size="sm">{item.assertion_id} · {item.instrument.id}</Heading><Badge>{item.status === 'unknown' ? 'Unknown' : item.status}</Badge><Text>{item.reason.replaceAll('_', ' ')}</Text><Text className="break-all">Version {item.instrument.version} · {item.instrument.sha256}</Text><Text>Observed: {show(item.observed)}</Text></Card.Body></Card>)}</Card.Body></Card>
                <Card><Card.Header heading="Native Flow progress" /><Card.Body><CodeBlock language="json" code={selected.flow === null ? 'Unknown' : JSON.stringify(selected.flow, null, 2)} wrap /></Card.Body></Card>
            </div>}
            <RsiContractReview contract={contract} documents={evidence.review_documents} />
        </main>
    </>;
}

function CostSummary({ costs }: { costs: Costs }) {
    return <div className="space-y-1" data-field="rsi-costs"><Text>Calls: {costs.calls} · Total reserved maxima: {costs.reserved_microusd} micro-USD</Text><Text>Pending maximum: {costs.pending_microusd} · Accounted settlement: {costs.accounted_charge_microusd} micro-USD</Text><Text>Known charge: {show(costs.known_charge_microusd)} · Uncertain calls: {costs.uncertain_calls}</Text><Text size="sm" color="muted">These are native account records, not a provider invoice. Cancellation and expiry do not refund uncertain work.</Text></div>;
}
