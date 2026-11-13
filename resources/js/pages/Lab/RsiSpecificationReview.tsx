import { Head, router } from '@inertiajs/react';
import { Badge, Button, Callout, Card, Checkbox, CodeBlock, Heading, Text } from '@particle-academy/react-fancy';
import { useEffect, useState } from 'react';
import { RsiContractReview, type InstrumentPin, type RsiContract, type RsiReviewDocuments } from '../../components/rsi-contract-review';
import { requestPaidRun } from '../../lib/paid-run';

export default function RsiSpecificationReview({ contract, reviewDocuments, instrumentCatalog }: { contract: RsiContract; reviewDocuments: RsiReviewDocuments; instrumentCatalog: InstrumentPin[] }) {
    const [reviewed, setReviewed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => setReviewed(false), [contract.digest, contract.status]);
    const action = contract.status === 'draft' ? 'request-approval' : contract.status === 'awaiting_approval' ? 'approve' : contract.status === 'approved' ? 'launch' : null;
    const label = action === 'request-approval' ? 'Request owner review' : action === 'approve' ? 'Approve this frozen contract' : 'Review paid GO and launch';
    async function submit() {
        if (!action || !reviewed || busy) return;
        setBusy(true); setError(null);
        const path = `/lab/benchmarks/${contract.id}/${action}`;
        try {
            const intent = action === 'launch' ? await requestPaidRun(path, {}) : null;
            router.post(path, action === 'launch' ? {} : { digest: contract.digest }, {
                ...(intent ? { headers: { 'X-Lab-Run-Id': intent } } : {}),
                onError: () => setError('The request was refused. Refresh and review the current frozen contract.'),
                onFinish: () => setBusy(false),
            });
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'The request was refused.'); setBusy(false); }
    }
    return <>
        <Head title={`${contract.name} · Frozen test review`} />
        <main className="mx-auto max-w-6xl space-y-6 p-6" data-surface="rsi-specification-review">
            <Button href="/lab/benchmarks" variant="ghost">Back to Lab</Button>
            <Heading as="h1" size="xl">{contract.name}</Heading><Badge>Unjudged</Badge>
            <Callout>Review the complete contract below. Instrument measurements do not confer acceptance or a package verdict. Unsupported profiles and missing measurements remain Unknown.</Callout>
            <RsiContractReview contract={contract} documents={reviewDocuments} />
            <Card><Card.Header heading="Installed deterministic instruments" description="A declared version and hash must match to produce a measurement." /><Card.Body>
                <CodeBlock language="json" code={JSON.stringify(instrumentCatalog, null, 2)} wrap />
            </Card.Body></Card>
            {action && <Card data-agent-off-limits="consent"><Card.Header heading="Owner review" /><Card.Body className="space-y-4">
                <Checkbox id="rsi-review-complete" data-action="rsi-review-complete" checked={reviewed} onCheckedChange={setReviewed} label="I reviewed the brief, fixtures, assertions, instrument identities, every lane reference and bound, revision and cost cap." />
                <Text>Approval freezes this digest. Launch uses the existing human-only paid GO surface and the same allocation.</Text>
                <Button id="rsi-spec-action" data-action="rsi-spec-action" disabled={!reviewed || busy} loading={busy} onClick={() => { void submit(); }}>{label}</Button>
                {error && <Callout color="red" role="alert">{error}</Callout>}
            </Card.Body></Card>}
        </main>
    </>;
}
