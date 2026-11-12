import { Badge, Card, CodeBlock, Heading, Text } from '@particle-academy/react-fancy';

export type InstrumentPin = { id: string; version: string; sha256: string };
export type RsiReviewDocuments = { complete: string; fixtures: string; assertions: string };
export type RsiContract = {
    id: string; name: string; revision: number; digest: string; status: string; archetype: string; surface_mode: string;
    specification: { contract_version: number; brief: string; fixtures: unknown[]; assertions: unknown[]; instruments: InstrumentPin[]; previous_revision: unknown };
    lanes: Array<Record<string, unknown> & { id: string; language: string; provider: string; model: string }>;
    budgets: { cost: number; cost_cap_microusd: number }; approved_by: number | null; approved_digest: string | null; approved_at: string | null;
};

/** Complete frozen v1 inputs, rendered as text. Declared identities are never evidence. */
export function RsiContractReview({ contract, documents }: { contract: RsiContract; documents: RsiReviewDocuments }) {
    return <div className="space-y-4" data-surface="rsi-contract" data-digest={contract.digest}>
        <Card><Card.Header heading="Frozen test contract" description={`Revision ${contract.revision} · ${contract.archetype} · ${contract.surface_mode}`} />
            <Card.Body className="space-y-3"><Badge>{contract.status}</Badge><Text className="break-all font-mono" data-field="rsi-digest">{contract.digest}</Text>
                <Text>Approval: {contract.approved_at ?? 'Not approved'} · Owner: {contract.approved_by ?? 'Unknown'}</Text>
                <Text className="break-all">Approved digest: {contract.approved_digest ?? 'Not approved'}</Text>
                <Text>Run cap: {contract.budgets.cost_cap_microusd} micro-USD. Paid execution requires its own owner GO.</Text>
            </Card.Body></Card>
        <Card><Card.Header heading="Brief" /><Card.Body><Text className="whitespace-pre-wrap break-words" data-field="rsi-brief">{contract.specification.brief}</Text></Card.Body></Card>
        <Card><Card.Header heading="Fixtures" description="Declared hashes and sizes; attachment support is checked separately." /><Card.Body><CodeBlock data-field="rsi-fixtures" language="json" code={documents.fixtures} wrap /></Card.Body></Card>
        <Card><Card.Header heading="Assertions and expectations" /><Card.Body><CodeBlock data-field="rsi-assertions" language="json" code={documents.assertions} wrap /></Card.Body></Card>
        <Card><Card.Header heading="Frozen instrument versions" /><Card.Body><CodeBlock data-field="rsi-instruments" language="json" code={JSON.stringify(contract.specification.instruments, null, 2)} wrap /></Card.Body></Card>
        <Heading as="h2" size="lg">Declared lanes and bounds</Heading>
        {contract.lanes.map(lane => <Card key={lane.id} data-contract-lane={lane.id}><Card.Header heading={lane.id} description={`${lane.language} · ${lane.provider} · ${lane.model}`} /><Card.Body>
            <CodeBlock language="json" code={JSON.stringify(lane, null, 2)} wrap />
        </Card.Body></Card>)}
        <Card><Card.Header heading="Complete v1 document" description="Includes the previous revision, all lane references, limits, instrument pins and approval identity." /><Card.Body>
            <CodeBlock data-field="rsi-complete-contract" language="json" code={documents.complete} wrap />
        </Card.Body></Card>
    </div>;
}
