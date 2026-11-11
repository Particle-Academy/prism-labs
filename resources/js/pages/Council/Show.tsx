import { Head, Link } from '@inertiajs/react';
import { Badge, Button, Callout, Card, Checkbox, CodeView, Heading, Modal, Select, Table, Tabs, Text, Textarea, Timeline } from '@particle-academy/react-fancy';
import { useState } from 'react';
import { type Detail, type Finding, type HostLink, type PetitionRow, type Policy, type RoundRow, send, usePoll, type Vote } from './parts/api';
import { navVariant } from './parts/nav';
import { label, POLICIES, severityTone, stateTone, verdictTone } from './parts/tone';

type Props = {
    detail: Detail;
    pollMs: number;
    host: HostLink;
    endpoints: { index: string; detail: string; owner: string; changeSilencePolicy: string; consumers: string; members: string };
};

type Action = 'approve_initiation' | 'reject_initiation' | 'approve' | 'reject' | 'revoke';

/**
 * Council/Show: one Change. Every free-text field from a reply (reason, note,
 * alternative, the raw message) is rendered as TEXT: Text with
 * whitespace-pre-wrap, or a read-only CodeView. Never HTML, never rendered
 * markup, so a reply cannot draw links, images or fake UI here.
 */
export default function Show({ detail: initial, pollMs, host, endpoints }: Props) {
    const [detail, setDetail, pollError] = usePoll<Detail>(endpoints.detail, initial, pollMs);
    // A HITL initiation has no rounds yet; its pros and cons are on Overview.
    const [tab, setTab] = useState(initial.change.state === 'awaiting_owner' ? 'overview' : 'rounds');
    const [action, setAction] = useState<Action | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const c = detail.change;

    const refresh = async () => {
        const answer = await send<Detail>('GET', endpoints.detail);
        if (answer.ok) setDetail(answer.json);
    };

    const setPolicy = async (address: string, scope: 'consumer' | 'change', policy: string) => {
        const answer =
            scope === 'consumer'
                ? await send('PUT', `${endpoints.consumers}/${encodeURIComponent(address)}/silence-policy`, { policy })
                : await send('PUT', endpoints.changeSilencePolicy, { address, policy: policy === '' ? null : policy });
        setMessage(answer.ok ? `Silence policy for ${address} saved.` : `Refused: ${answer.json.error?.code ?? answer.status}`);
        await refresh();
    };

    return (
        <>
            <Head title={`${c.ref} - Change council`} />
            <div className="mx-auto max-w-7xl space-y-6 p-6" data-surface="council-show" data-change-ref={c.ref} data-change-state={c.state}>
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        {host && (
                            <Button data-action="council-host" href={host.url} variant="ghost" size="sm" id="council-host">
                                {host.label}
                            </Button>
                        )}
                        <Button data-action="council-nav-changes" href={endpoints.index} as={Link} variant={navVariant('changes', 'changes')} size="sm" id="council-nav-changes">
                            All Changes
                        </Button>
                        <Button data-action="council-nav-members" href={endpoints.members} as={Link} variant={navVariant('changes', 'members')} size="sm" id="council-nav-members">
                            Members
                        </Button>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Heading as="h1" size="2xl" weight="semibold">
                            {c.ref}
                        </Heading>
                        <Badge color={stateTone(c.state)} variant="soft" size="lg" data-testid="change-state">
                            {label(c.state)}
                        </Badge>
                        {c.approved_unopposed && <Badge color="amber">approved unopposed: nobody declares this package</Badge>}
                        {c.approved_with_notes && <Badge color="slate">approved with notes</Badge>}
                        {c.late_conflict && <Badge color="red">late conflict from a catching-up consumer</Badge>}
                        {c.blocking_below_threshold && (
                            <Badge color="orange" data-testid="change-below-threshold">
                                blocking conflicts below the threshold: read them
                            </Badge>
                        )}
                    </div>
                    <Text size="lg">{c.title}</Text>
                    <Text color="muted" size="sm">
                        {label(c.initiation_source)} by {c.initiator} ({c.whitelisted ? 'whitelisted' : 'not whitelisted'})
                        {c.decided_by ? ` - decided by ${c.decided_by}` : ''}
                        {c.deadline ? ` - round ${c.current_round} deadline ${c.deadline}` : ''}
                    </Text>
                </div>

                {pollError && <Callout color="red">Could not refresh ({pollError}). Showing the last answer.</Callout>}
                {message && (
                    <Callout color="blue" dismissible onDismiss={() => setMessage(null)} data-testid="council-message">
                        {message}
                    </Callout>
                )}

                <OwnerActions detail={detail} onPick={setAction} />

                <Tabs activeTab={tab} onTabChange={setTab} variant="underline">
                    <Tabs.List className="overflow-x-auto overflow-y-hidden">
                        <Tabs.Tab data-action="council-tab-overview" value="overview" id="council-tab-overview" className="shrink-0 whitespace-nowrap focus-visible:-outline-offset-2">
                            Overview
                        </Tabs.Tab>
                        <Tabs.Tab data-action="council-tab-rounds" value="rounds" id="council-tab-rounds" className="shrink-0 whitespace-nowrap focus-visible:-outline-offset-2">
                            Rounds ({detail.rounds.length})
                        </Tabs.Tab>
                        <Tabs.Tab data-action="council-tab-evidence" value="evidence" id="council-tab-evidence" className="shrink-0 whitespace-nowrap focus-visible:-outline-offset-2">
                            Evidence ({c.evidence.findings?.length ?? 0})
                        </Tabs.Tab>
                        <Tabs.Tab data-action="council-tab-silence" value="silence" id="council-tab-silence" className="shrink-0 whitespace-nowrap focus-visible:-outline-offset-2">
                            Silence policy
                        </Tabs.Tab>
                        <Tabs.Tab data-action="council-tab-audit" value="audit" id="council-tab-audit" className="shrink-0 whitespace-nowrap focus-visible:-outline-offset-2">
                            Audit ({detail.audit.length})
                        </Tabs.Tab>
                    </Tabs.List>
                    <Tabs.Panels className="pt-4">
                        <Tabs.Panel value="overview">
                            <Overview detail={detail} />
                        </Tabs.Panel>
                        <Tabs.Panel value="rounds">
                            <div className="space-y-6">
                                {detail.rounds.length === 0 && <Text color="muted">No round yet. A non-whitelisted initiation waits for the owner before any petition is sent.</Text>}
                                {[...detail.rounds].reverse().map((round) => (
                                    <Round key={round.number} round={round} />
                                ))}
                            </div>
                        </Tabs.Panel>
                        <Tabs.Panel value="evidence">
                            <Evidence findings={c.evidence.findings ?? []} links={c.links} />
                        </Tabs.Panel>
                        <Tabs.Panel value="silence">
                            <SilencePolicies detail={detail} onSet={setPolicy} />
                        </Tabs.Panel>
                        <Tabs.Panel value="audit">
                            <Timeline
                                data-testid="council-audit"
                                className="min-w-0 [overflow-wrap:anywhere]"
                                events={detail.audit.map((e) => ({
                                    date: e.at.replace('T', ' ').slice(0, 19),
                                    title: `${e.type} - ${e.actor}`,
                                    description: `#${e.seq} ${JSON.stringify(e.data)}  hash ${e.hash.slice(0, 12)}`,
                                    color: e.actor.startsWith('owner:') ? 'violet' : e.type.includes('escalat') || e.type.includes('conflict') ? 'amber' : 'zinc',
                                }))}
                            />
                        </Tabs.Panel>
                    </Tabs.Panels>
                </Tabs>

                <OwnerModal
                    action={action}
                    detail={detail}
                    endpoint={endpoints.owner}
                    onClose={() => setAction(null)}
                    onDone={async (text) => {
                        setAction(null);
                        setMessage(text);
                        await refresh();
                    }}
                />
            </div>
        </>
    );
}

function OwnerActions({ detail, onPick }: { detail: Detail; onPick: (a: Action) => void }) {
    const can = (a: string) => detail.owner_actions.includes(a);
    if (!can('approve_initiation') && !can('override') && !can('revoke')) return null;

    return (
        <Card data-testid="council-owner-actions">
            <Card.Body className="space-y-3">
                <Heading as="h2" size="md" weight="semibold">
                    Owner actions
                </Heading>
                {detail.change.state === 'awaiting_owner' && (
                    <Callout color="amber">
                        This initiation is waiting for you. No petition has been sent. Read the initiating agent's pros and cons under Overview.
                    </Callout>
                )}
                <div className="flex flex-wrap gap-2">
                    {can('approve_initiation') && (
                        <Button data-action="approve-initiation" color="green" onClick={() => onPick('approve_initiation')} id="council-approve-initiation">
                            Approve: petition the consumers
                        </Button>
                    )}
                    {can('reject_initiation') && (
                        <Button data-action="reject-initiation" color="red" variant="ghost" onClick={() => onPick('reject_initiation')} id="council-reject-initiation">
                            Reject initiation
                        </Button>
                    )}
                    {can('override') && detail.change.state !== 'approved' && (
                        <Button data-action="override-approve" color="green" variant="ghost" onClick={() => onPick('approve')} id="council-override-approve">
                            Approve (override)
                        </Button>
                    )}
                    {can('override') && detail.change.state !== 'rejected' && (
                        <Button data-action="override-reject" color="red" variant="ghost" onClick={() => onPick('reject')} id="council-override-reject">
                            Reject (override)
                        </Button>
                    )}
                    {can('revoke') && (
                        <Button data-action="revoke" color="red" variant="ghost" onClick={() => onPick('revoke')} id="council-revoke">
                            Revoke
                        </Button>
                    )}
                </div>
            </Card.Body>
        </Card>
    );
}

function Overview({ detail }: { detail: Detail }) {
    const c = detail.change;

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card>
                <Card.Body className="space-y-3">
                    <Heading as="h3" size="sm" weight="semibold">
                        What changes
                    </Heading>
                    <Text className="whitespace-pre-wrap">{c.summary}</Text>
                    <div className="flex flex-wrap gap-1">
                        {c.classes.map((k) => (
                            <Badge key={k} color="zinc" variant="outline">
                                {k}
                            </Badge>
                        ))}
                    </div>
                    <Table className="overflow-x-auto">
                        <Table.Body>
                            {c.packages.map((p) => (
                                <Table.Row key={`${p.ecosystem}:${p.name}`}>
                                    <Table.Cell>{p.ecosystem}</Table.Cell>
                                    <Table.Cell className="font-medium">{p.name}</Table.Cell>
                                    <Table.Cell className="text-sm">{p.from || p.to ? `${p.from ?? '?'} -> ${p.to ?? '?'}` : ''}</Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table>
                </Card.Body>
            </Card>
            <Card>
                <Card.Body className="space-y-3">
                    <Heading as="h3" size="sm" weight="semibold">
                        Current approach (frozen per round by its digest)
                    </Heading>
                    <CodeView value={c.approach} readOnly language="plaintext" minHeight={120} />
                    {(c.pros || c.cons) && (
                        <>
                            <Heading as="h3" size="sm" weight="semibold">
                                The initiating agent's case
                            </Heading>
                            <Text size="sm" weight="medium">
                                Pros
                            </Text>
                            <Text size="sm" className="whitespace-pre-wrap">
                                {c.pros ?? '-'}
                            </Text>
                            <Text size="sm" weight="medium">
                                Cons
                            </Text>
                            <Text size="sm" className="whitespace-pre-wrap">
                                {c.cons ?? '-'}
                            </Text>
                        </>
                    )}
                    {detail.decisions.length > 0 && (
                        <>
                            <Heading as="h3" size="sm" weight="semibold">
                                Decisions
                            </Heading>
                            {detail.decisions.map((d) => (
                                <Text key={d.id} size="sm" className="whitespace-pre-wrap">
                                    {d.outcome} by {d.decided_by}
                                    {d.reason ? `: ${d.reason}` : ''}
                                    {d.acknowledged.length > 0 ? ` (acknowledged conflict votes ${d.acknowledged.join(', ')})` : ''}
                                </Text>
                            ))}
                        </>
                    )}
                </Card.Body>
            </Card>
        </div>
    );
}

function Round({ round }: { round: RoundRow }) {
    const counts = round.tally?.counts ?? {};
    const w = round.weighing;

    return (
        <section className="space-y-3" data-round={round.number}>
            <div className="flex flex-wrap items-center gap-2">
                <Heading as="h3" size="md" weight="semibold">
                    Round {round.number}
                </Heading>
                <Badge color={round.closed_at ? 'zinc' : 'blue'} variant="soft">
                    {round.closed_at ? `closed: ${label(round.outcome ?? '')}` : 'open'}
                </Badge>
                <Text size="xs" color="muted">
                    {round.approach_digest.slice(0, 19)}... closes {round.extended_until ?? round.closes_at}
                    {round.extended_until ? ' (extended once)' : ''}
                </Text>
                {Object.entries(counts)
                    .filter(([, n]) => n > 0)
                    .map(([k, n]) => (
                        <Badge key={k} color={verdictTone(k)} variant="outline" size="sm">
                            {n} {k}
                        </Badge>
                    ))}
            </div>
            {w && (
                <Callout color={w.blocks ? 'red' : w.below_threshold.length + w.advisory.length > 0 ? 'orange' : 'zinc'} data-testid="round-weighing">
                    Blocking weight {w.blocking_weight} of threshold {w.threshold}
                    {w.live ? ' (so far: this round is open)' : ''}.{' '}
                    {w.blocks
                        ? `${w.blocking.length} blocking conflict(s) reach it and block.`
                        : w.below_threshold.length > 0
                          ? `${w.below_threshold.length} blocking conflict(s) do not reach it, so they are notes, not blocks.`
                          : 'Nothing blocks.'}
                    {w.advisory.length > 0 ? ` ${w.advisory.length} from weight-0 (advisory) members.` : ''}
                </Callout>
            )}
            <div className="grid gap-4 lg:grid-cols-2">
                {round.petitions.map((p) => (
                    <PetitionCard key={p.id} petition={p} />
                ))}
                {round.petitions.length === 0 && <Text color="muted">Nobody declares or watches these packages. An empty room is not a room that agreed.</Text>}
            </div>
        </section>
    );
}

function PetitionCard({ petition: p }: { petition: PetitionRow }) {
    const latest = [...p.votes].reverse().find((v) => v.status === 'counted' || v.status === 'late') ?? null;

    return (
        <Card data-agent={p.agent_address} data-outcome={p.outcome ?? 'pending'}>
            <Card.Body className="space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Text weight="semibold">{p.agent_address}</Text>
                    <Badge color={p.tier === 'required' ? 'blue' : 'zinc'} variant="outline" size="sm">
                        {p.tier}
                    </Badge>
                    <Badge color={p.weight === 0 ? 'zinc' : 'violet'} variant="outline" size="sm" data-weight={p.weight}>
                        weight {p.weight}
                        {p.weight === 0 ? ' (advisory)' : ''}
                    </Badge>
                    <Badge color={verdictTone(latest?.verdict ?? p.outcome)} variant="soft">
                        {latest?.verdict ?? p.outcome ?? 'no answer yet'}
                    </Badge>
                    {p.catch_up && (
                        <Badge color="amber" size="sm">
                            catching up
                        </Badge>
                    )}
                    {p.withdrawn_at && (
                        <Badge color="zinc" variant="outline" size="sm" data-withdrawn={p.withdrawn_at}>
                            withdrawn: member removed, not counted
                        </Badge>
                    )}
                </div>
                <Text size="xs" color="muted">
                    {p.why}. Delivery: {p.delivery}
                    {p.delivery_detail ? ` (${p.delivery_detail})` : ''}. On silence: {label(p.effective_silence_policy)}.
                </Text>
                {p.votes.map((v) => (
                    <VoteBlock key={v.id} vote={v} />
                ))}
            </Card.Body>
        </Card>
    );
}

function VoteBlock({ vote: v }: { vote: Vote }) {
    return (
        <div className="space-y-2 border-t border-zinc-200 pt-2 dark:border-zinc-800" data-vote={v.id} data-vote-status={v.status}>
            <div className="flex flex-wrap items-center gap-2">
                <Badge color={v.status === 'counted' ? 'green' : v.status === 'late' ? 'amber' : 'zinc'} variant="outline" size="sm">
                    {v.status}
                    {v.problem ? `: ${v.problem}` : ''}
                </Badge>
                <Text size="xs" color="muted">
                    vote #{v.id} from {v.from_address} at {v.received_at}
                </Text>
            </div>
            {v.flags.length > 0 && (
                <Callout color="amber" data-testid="vote-flags">
                    Injection heuristics (shown for you; they do not change the vote): {v.flags.join('; ')}
                </Callout>
            )}
            {v.impact.length > 0 && (
                <Table className="overflow-x-auto">
                    <Table.Head>
                        <Table.Row>
                            <Table.Cell header>App</Table.Cell>
                            <Table.Cell header>Severity</Table.Cell>
                            <Table.Cell header>Paths</Table.Cell>
                            <Table.Cell header>Note</Table.Cell>
                        </Table.Row>
                    </Table.Head>
                    <Table.Body>
                        {v.impact.map((row, i) => (
                            <Table.Row key={i}>
                                <Table.Cell>{row.app}</Table.Cell>
                                <Table.Cell>
                                    <Badge color={severityTone(row.severity)} size="sm">
                                        {row.severity}
                                    </Badge>
                                </Table.Cell>
                                <Table.Cell className="text-xs">{row.paths.join(', ')}</Table.Cell>
                                <Table.Cell className="text-sm whitespace-pre-wrap">{row.note}</Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table>
            )}
            {v.reason && (
                <Text size="sm" className="whitespace-pre-wrap">
                    Reason: {v.reason}
                </Text>
            )}
            {v.alternative && (
                <Text size="sm" className="whitespace-pre-wrap" data-testid="vote-alternative">
                    Suggested alternative (input for the author; never applied): {v.alternative}
                </Text>
            )}
            <CodeView value={v.raw_text} readOnly language="plaintext" maxHeight={180} />
        </div>
    );
}

function Evidence({ findings, links }: { findings: Finding[]; links: Record<string, unknown> }) {
    return (
        <div className="space-y-3">
            <Text size="sm" color="muted">
                Links: {Object.entries(links).map(([k, v]) => `${k}: ${String(v)}`).join('; ') || 'none'}
            </Text>
            {findings.length === 0 ? (
                <Text color="muted">No detector findings attached (a manual proposal, or a behaviour change no extractor can see).</Text>
            ) : (
                <Table className="overflow-x-auto" data-testid="council-findings">
                    <Table.Head>
                        <Table.Row>
                            <Table.Cell header>Class</Table.Cell>
                            <Table.Cell header>Subject</Table.Cell>
                            <Table.Cell header>Change</Table.Cell>
                            <Table.Cell header>Severity</Table.Cell>
                        </Table.Row>
                    </Table.Head>
                    <Table.Body>
                        {findings.map((f) => (
                            <Table.Row key={f.fingerprint} data-fingerprint={f.fingerprint}>
                                <Table.Cell>
                                    <Badge color="zinc" variant="outline" size="sm">
                                        {f.class}
                                    </Badge>
                                </Table.Cell>
                                <Table.Cell className="text-sm">
                                    {f.subject}
                                    <div className="text-xs text-zinc-500">
                                        {f.file}
                                        {f.line ? `:${f.line}` : ''} {f.note}
                                    </div>
                                </Table.Cell>
                                <Table.Cell className="text-xs">{f.before !== null || f.after !== null ? `${f.before ?? '-'} -> ${f.after ?? '-'}` : ''}</Table.Cell>
                                <Table.Cell>
                                    <Badge color={f.waived ? 'zinc' : f.severity === 'major' ? 'red' : 'slate'} size="sm">
                                        {f.waived ? `waived: ${f.waived.kind}` : f.severity}
                                    </Badge>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table>
            )}
        </div>
    );
}

function SilencePolicies({ detail, onSet }: { detail: Detail; onSet: (address: string, scope: 'consumer' | 'change', policy: string) => void }) {
    const latest = detail.rounds[detail.rounds.length - 1];
    const petitions = latest?.petitions ?? [];
    const override = (address: string): string => detail.change.silence_overrides[address] ?? '';

    return (
        <div className="space-y-3">
            <Text size="sm" color="muted">
                What happens when a required voter stays silent, is unreachable, or sends an unreadable reply. The consumer-wide policy applies to every
                Change; an override here applies to this Change only and wins.
            </Text>
            {petitions.length === 0 ? (
                <Text color="muted">No consumer has been petitioned on this Change yet.</Text>
            ) : (
                <Table className="overflow-x-auto" data-testid="council-silence">
                    <Table.Head>
                        <Table.Row>
                            <Table.Cell header>Agent</Table.Cell>
                            <Table.Cell header>Consumer-wide</Table.Cell>
                            <Table.Cell header>This Change only</Table.Cell>
                            <Table.Cell header>In force</Table.Cell>
                        </Table.Row>
                    </Table.Head>
                    <Table.Body>
                        {petitions.map((p) => (
                            <Table.Row key={p.agent_address} data-consumer={p.agent_address}>
                                <Table.Cell className="font-medium">{p.agent_address}</Table.Cell>
                                <Table.Cell>
                                    <Select
                                        data-field="consumer-silence-policy"
                                        id={`council-policy-${p.agent_address}`}
                                        aria-label={`Consumer-wide silence policy for ${p.agent_address}`}
                                        value={override(p.agent_address) ? '' : p.effective_silence_policy}
                                        onValueChange={(value) => value !== '' && onSet(p.agent_address, 'consumer', value)}
                                        list={override(p.agent_address) ? [{ value: '', label: '(overridden on this Change)' }, ...POLICIES] : POLICIES}
                                    />
                                </Table.Cell>
                                <Table.Cell>
                                    <Select
                                        data-field="change-silence-policy"
                                        id={`council-override-${p.agent_address}`}
                                        aria-label={`Silence policy for ${p.agent_address} on this Change`}
                                        value={override(p.agent_address)}
                                        onValueChange={(value) => onSet(p.agent_address, 'change', value)}
                                        list={[{ value: '', label: 'no override' }, ...POLICIES]}
                                    />
                                </Table.Cell>
                                <Table.Cell>
                                    <Badge color={p.effective_silence_policy === 'escalate' ? 'amber' : 'blue'} variant="soft">
                                        {label(p.effective_silence_policy as Policy)}
                                    </Badge>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table>
            )}
        </div>
    );
}

const PATHS: Record<Action, string> = {
    approve_initiation: 'approve-initiation',
    reject_initiation: 'reject-initiation',
    approve: 'override',
    reject: 'override',
    revoke: 'revoke',
};

const TITLES: Record<Action, string> = {
    approve_initiation: 'Approve the initiation and petition the consumers',
    reject_initiation: 'Reject the initiation',
    approve: 'Approve this Change (owner override)',
    reject: 'Reject this Change (owner override)',
    revoke: 'Revoke the approval',
};

function OwnerModal({ action, detail, endpoint, onClose, onDone }: { action: Action | null; detail: Detail; endpoint: string; onClose: () => void; onDone: (message: string) => void }) {
    const [reason, setReason] = useState('');
    const [acked, setAcked] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const conflicts = detail.rounds.flatMap((r) => r.petitions.flatMap((p) => p.votes.filter((v) => detail.open_conflicts.includes(v.id)).map((v) => ({ vote: v, agent: p.agent_address }))));
    const needsAck = action === 'approve' && conflicts.length > 0;
    const reasonOptional = action === 'approve_initiation';
    const ready = (reasonOptional || reason.trim() !== '') && (!needsAck || conflicts.every((c) => acked.includes(c.vote.id)));

    const submit = async () => {
        if (action === null) return;
        setBusy(true);
        setError(null);
        const body =
            action === 'approve' || action === 'reject'
                ? { decision: action, reason, acknowledge: acked }
                : action === 'approve_initiation'
                  ? reason.trim() === ''
                      ? {}
                      : { note: reason }
                  : { reason };
        const answer = await send('POST', `${endpoint}/${PATHS[action]}`, body);
        setBusy(false);
        if (!answer.ok) {
            setError(answer.json.error?.code ?? `http_${answer.status}`);
            return;
        }
        setReason('');
        setAcked([]);
        onDone(`${TITLES[action]}: done.`);
    };

    return (
        <Modal open={action !== null} onClose={onClose} size="lg">
            <Modal.Header>{action ? TITLES[action] : ''}</Modal.Header>
            <Modal.Body className="space-y-4">
                <Textarea
                    data-field="owner-reason"
                    id="council-owner-reason"
                    label={reasonOptional ? 'Note (optional)' : 'Reason (required, recorded in the audit log)'}
                    value={reason}
                    onValueChange={setReason}
                    minRows={3}
                />
                {needsAck && (
                    <div className="space-y-2" data-testid="council-acknowledge">
                        <Text size="sm" weight="medium">
                            Acknowledge each open conflict you are approving over:
                        </Text>
                        {conflicts.map(({ vote, agent }) => (
                            <Checkbox
                                data-field="acknowledge-conflict"
                                key={vote.id}
                                id={`council-ack-${vote.id}`}
                                label={`${agent}: ${vote.impact.map((i) => `${i.severity} in ${i.app}`).join(', ')} (vote #${vote.id})`}
                                checked={acked.includes(vote.id)}
                                onCheckedChange={(on) => setAcked((list) => (on ? [...list, vote.id] : list.filter((id) => id !== vote.id)))}
                            />
                        ))}
                    </div>
                )}
                {error && <Callout color="red">Refused: {error}</Callout>}
            </Modal.Body>
            <Modal.Footer>
                <div className="flex justify-end gap-2">
                    <Button data-action="owner-cancel" variant="ghost" onClick={onClose} id="council-owner-cancel">
                        Cancel
                    </Button>
                    <Button data-action="owner-confirm" color={action === 'approve' || action === 'approve_initiation' ? 'green' : 'red'} disabled={!ready || busy} loading={busy} onClick={submit} id="council-owner-confirm">
                        Confirm
                    </Button>
                </div>
            </Modal.Footer>
        </Modal>
    );
}
