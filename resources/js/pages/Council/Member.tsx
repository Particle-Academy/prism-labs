import { Head, Link } from '@inertiajs/react';
import { Badge, Button, Callout, Card, Heading, Input, NumberField, Select, StatList, Table, Text, Textarea } from '@particle-academy/react-fancy';
import { useState } from 'react';
import { type HostLink, type MemberDetail, type MemberRow, type RecordRow, send, refusal, usePoll } from './parts/api';
import { navVariant, safeUrl } from './parts/nav';
import { censusRequestLabel, label, POLICIES, severityTone, sourceTone, verdictTone } from './parts/tone';

type Props = {
    detail: MemberDetail;
    pollMs: number;
    host: HostLink;
    /** Draw the Changes/Members row; false when the host renders the pages inside its own navigation. */
    showNav?: boolean;
    /** Whether the hook allows `owner` for this viewer. Display only: each PUT asks again. */
    canSetPolicy: boolean;
    endpoints: { index: string; members: string; detail: string; consumers: string; show: string };
};

/**
 * Council/Member: one member's project, owner controls (weight, silence policy,
 * project fields, removal from the council) and voting record, newest first. A
 * removed member keeps its page and its record. Every member string is
 * agent-supplied and rendered as text; the project URL becomes a link only when
 * it is absolute http or https.
 */
export default function Member({ detail: initial, pollMs, host, showNav = true, canSetPolicy, endpoints }: Props) {
    const [detail, setDetail, pollError] = usePoll<MemberDetail>(endpoints.detail, initial, pollMs);
    const [message, setMessage] = useState<string | null>(null);
    const m = detail.member;
    const url = safeUrl(m.project_url);

    const saved = (member: MemberRow, text: string) => {
        setDetail({ ...detail, member });
        setMessage(text);
    };

    return (
        <>
            <Head title={`${m.name} - Council members`} />
            <div className="mx-auto max-w-7xl space-y-6 p-6" data-surface="council-member" data-member={m.address}>
                <div className="flex flex-wrap items-center gap-2">
                    {host && (
                        <Button data-action="council-host" href={host.url} variant="ghost" size="sm" id="council-host">
                            {host.label}
                        </Button>
                    )}
                    {showNav && (
                        <>
                            <Button data-action="council-nav-changes" href={endpoints.index} as={Link} variant={navVariant('members', 'changes')} size="sm" id="council-nav-changes">
                                Changes
                            </Button>
                            <Button data-action="council-nav-members" href={endpoints.members} as={Link} variant={navVariant('members', 'members')} size="sm" id="council-nav-members">
                                Members
                            </Button>
                        </>
                    )}
                </div>

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <Heading as="h1" size="2xl" weight="semibold">
                                {m.name}
                            </Heading>
                            <Badge color={m.weight === 0 ? 'zinc' : 'violet'} variant="soft" data-testid="member-weight">
                                weight {m.weight}
                                {m.weight === 0 ? ' (advisory)' : ''}
                            </Badge>
                            {m.internal && <Badge color="zinc">internal: never petitioned</Badge>}
                            {m.whitelisted && <Badge color="green">whitelisted</Badge>}
                            {m.removed_at && (
                                <Badge color="red" variant="soft" data-testid="member-removed">
                                    former member
                                </Badge>
                            )}
                        </div>
                        <Text color="muted" size="sm">
                            {m.address}
                        </Text>
                    </div>
                    <StatList
                        data-testid="member-stats"
                        items={[
                            { key: 'cast', label: 'Votes cast', value: `${m.stats.cast} of ${m.stats.petitions}` },
                            { key: 'approve', label: 'Approve', value: m.stats.approve },
                            { key: 'conflict', label: 'Conflict', value: m.stats.conflict },
                            { key: 'abstain', label: 'Abstain', value: m.stats.abstain },
                            { key: 'missed', label: 'Missed', value: m.stats.missed },
                        ]}
                    />
                </div>

                {message && (
                    <Callout color="blue" dismissible onDismiss={() => setMessage(null)} data-testid="council-message">
                        {message}
                    </Callout>
                )}
                {m.removed_at && (
                    <Callout color="zinc" data-testid="member-removed-note">
                        Removed from the council on {m.removed_at}. Its voting record and audit history are kept; it gets no new petitions, and its petitions in
                        rounds that were open then were withdrawn, so they are never counted as silent.
                    </Callout>
                )}
                {pollError && (
                    <Callout color="red" data-testid="council-poll-error">
                        Could not refresh ({pollError}). Showing the last answer.
                    </Callout>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card data-testid="member-project">
                        <Card.Body className="space-y-3">
                            <Heading as="h2" size="lg" weight="semibold">
                                Project
                            </Heading>
                            <Fact name="Display name" value={m.name} />
                            <Fact name="Project" value={m.project_name} />
                            <Fact name="Workspace" value={m.workspace} />
                            <div>
                                <Text size="xs" color="muted">
                                    Description
                                </Text>
                                <Text size="sm" className="whitespace-pre-wrap" data-testid="member-description">
                                    {m.project_description ?? '-'}
                                </Text>
                            </div>
                            <div>
                                <Text size="xs" color="muted">
                                    Repository / site
                                </Text>
                                {url ? (
                                    <Button data-action="member-project-url" href={url} target="_blank" rel="noopener noreferrer nofollow" variant="ghost" size="sm" id="council-member-url">
                                        {url}
                                    </Button>
                                ) : (
                                    <Text size="sm">-</Text>
                                )}
                            </div>
                            <div>
                                <Text size="xs" color="muted">
                                    Source
                                </Text>
                                <Badge color={sourceTone(m.source)} variant="soft" size="sm" data-source={m.source}>
                                    {m.source}
                                </Badge>
                            </div>
                            <Fact name="Census" value={m.census_at ?? 'never'} />
                            {m.census_request && (
                                <div data-testid="member-census-request">
                                    <Text size="xs" color="muted">
                                        Census request
                                    </Text>
                                    <Badge color="amber" variant="soft" size="sm" data-census-request={m.census_request.delivery}>
                                        {censusRequestLabel(m.census_request)}
                                    </Badge>
                                    <Text size="xs" color="muted">
                                        queued {m.census_request.queued_at}
                                        {m.census_request.sent_at ? `, sent ${m.census_request.sent_at}` : ''}
                                    </Text>
                                </div>
                            )}
                            <Fact name="Reachable" value={m.reachable === null ? 'unknown' : m.reachable ? 'yes' : 'no'} />
                            <Fact name="Notes" value={m.notes} />
                            <div className="space-y-1">
                                <Text size="xs" color="muted">
                                    {m.source === 'census' ? 'Installed packages, from its census' : 'Declared packages'} (a required voter on these)
                                </Text>
                                {m.packages.length === 0 ? (
                                    <Text size="sm">-</Text>
                                ) : (
                                    <div className="flex flex-wrap gap-1" data-testid="member-packages">
                                        {m.packages.map((p) => (
                                            <Badge key={`${p.ecosystem}:${p.name}`} color="blue" variant="outline" size="sm">
                                                {p.name} {p.version ?? p.constraint ?? ''}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="space-y-1">
                                <Text size="xs" color="muted">
                                    Watched packages (informed, never required)
                                </Text>
                                {m.watches.length === 0 ? (
                                    <Text size="sm">-</Text>
                                ) : (
                                    <div className="flex flex-wrap gap-1" data-testid="member-watches">
                                        {m.watches.map((p) => (
                                            <Badge key={`${p.ecosystem}:${p.name}`} color="zinc" variant="outline" size="sm">
                                                {p.name}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </Card.Body>
                    </Card>

                    {canSetPolicy && <OwnerControls member={m} endpoints={endpoints} onSaved={saved} onRefused={setMessage} />}
                </div>

                <Card data-testid="member-record">
                    <Card.Body className="space-y-3">
                        <Heading as="h2" size="lg" weight="semibold">
                            Voting record
                        </Heading>
                        <Text color="muted" size="sm">
                            Every petition sent to {m.name}, newest first. Blocking threshold now {detail.blocking_threshold.value}.
                        </Text>
                        {detail.record.length === 0 ? (
                            <Text color="muted">No petitions yet.</Text>
                        ) : (
                            <Table className="overflow-x-auto">
                                <Table.Head>
                                    <Table.Row>
                                        <Table.Cell header>Change</Table.Cell>
                                        <Table.Cell header>Round</Table.Cell>
                                        <Table.Cell header>Verdict</Table.Cell>
                                        <Table.Cell header>Reason and impact</Table.Cell>
                                        <Table.Cell header>Sent</Table.Cell>
                                        <Table.Cell header>Replied</Table.Cell>
                                    </Table.Row>
                                </Table.Head>
                                <Table.Body>
                                    {detail.record.map((r) => (
                                        <RecordLine key={r.petition} row={r} show={endpoints.show} />
                                    ))}
                                </Table.Body>
                            </Table>
                        )}
                    </Card.Body>
                </Card>
            </div>
        </>
    );
}

function Fact({ name, value }: { name: string; value: string | null }) {
    return (
        <div>
            <Text size="xs" color="muted">
                {name}
            </Text>
            <Text size="sm">{value ?? '-'}</Text>
        </div>
    );
}

function RecordLine({ row: r, show }: { row: RecordRow; show: string }) {
    return (
        <Table.Row data-petition={r.petition} data-verdict={r.verdict ?? 'pending'}>
            <Table.Cell>
                {r.change.ref ? (
                    <Link
                        href={`${show}/${r.change.ref}`}
                        className="font-medium text-violet-700 underline underline-offset-2 hover:text-violet-900 dark:text-violet-300 dark:hover:text-violet-100"
                        id={`council-record-${r.petition}`}
                    >
                        {r.change.ref}
                    </Link>
                ) : (
                    '-'
                )}
                <div className="text-sm">{r.change.title ?? ''}</div>
                <div className="text-xs text-zinc-500">
                    {r.tier}
                    {r.change.state ? `, change ${label(r.change.state)}` : ''}
                </div>
            </Table.Cell>
            <Table.Cell className="text-sm">{r.round}</Table.Cell>
            <Table.Cell>
                <div className="flex flex-wrap gap-1">
                    <Badge color={verdictTone(r.verdict)} variant="soft" size="sm">
                        {r.verdict ?? 'no answer yet'}
                    </Badge>
                    {r.late && (
                        <Badge color="amber" variant="outline" size="sm">
                            late
                        </Badge>
                    )}
                    {r.withdrawn && r.verdict !== 'withdrawn' && (
                        <Badge color="zinc" variant="outline" size="sm">
                            withdrawn
                        </Badge>
                    )}
                    {r.severity && (
                        <Badge color={severityTone(r.severity)} size="sm">
                            {r.severity}
                        </Badge>
                    )}
                </div>
            </Table.Cell>
            <Table.Cell className="max-w-md space-y-1 text-sm">
                {r.reason && <div className="whitespace-pre-wrap">{r.reason}</div>}
                {r.impact.map((i, n) => (
                    <div key={n} className="text-xs">
                        <Badge color={severityTone(i.severity)} size="sm">
                            {i.severity}
                        </Badge>{' '}
                        {i.app}
                        {i.paths.length > 0 ? ` (${i.paths.join(', ')})` : ''}: <span className="whitespace-pre-wrap">{i.note}</span>
                    </div>
                ))}
                {!r.reason && r.impact.length === 0 && <span className="text-zinc-500">-</span>}
            </Table.Cell>
            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">{r.sent_at ?? (r.delivery === 'queued' ? 'queued' : r.delivery)}</Table.Cell>
            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">{r.replied_at ?? '-'}</Table.Cell>
        </Table.Row>
    );
}

function OwnerControls({
    member: m,
    endpoints,
    onSaved,
    onRefused,
}: {
    member: MemberRow;
    endpoints: Props['endpoints'];
    onSaved: (member: MemberRow, message: string) => void;
    onRefused: (message: string) => void;
}) {
    const [weight, setWeight] = useState<number | null>(m.weight);
    const [name, setName] = useState(m.project_name ?? '');
    const [description, setDescription] = useState(m.project_description ?? '');
    const [url, setUrl] = useState(m.project_url ?? '');
    const [busy, setBusy] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [reason, setReason] = useState('');
    const urlError = url.trim() !== '' && safeUrl(url.trim()) === null ? 'Must be an absolute http or https URL.' : undefined;

    const put = async (key: string, path: string, body: unknown, done: string, method: 'PUT' | 'POST' = 'PUT') => {
        setBusy(key);
        const answer = await send<{ member?: MemberRow; consumer?: unknown }>(method, path, body);
        setBusy(null);
        if (!answer.ok) {
            onRefused(refusal(answer));
            return false;
        }
        if (answer.json.member) onSaved(answer.json.member, done);

        return true;
    };

    const saveWeight = () => {
        if (weight === null || !Number.isInteger(weight) || weight < 0) {
            onRefused('The weight is a whole number, 0 or more.');
            return;
        }
        void put('weight', `${endpoints.detail}/weight`, { weight }, `Weight for ${m.address} set to ${weight}.`);
    };

    const savePolicy = async (policy: string) => {
        if (policy === m.silence_policy) return;
        if (await put('policy', `${endpoints.consumers}/${encodeURIComponent(m.address)}/silence-policy`, { policy }, '')) {
            onSaved({ ...m, silence_policy: policy as MemberRow['silence_policy'] }, `Silence policy for ${m.address} saved.`);
        }
    };

    const saveProject = () => {
        if (urlError) return;
        void put(
            'project',
            `${endpoints.detail}/project`,
            { project_name: name.trim() || null, project_description: description.trim() || null, project_url: url.trim() || null },
            `Project for ${m.address} saved.`,
        );
    };

    const remove = async () => {
        const body = reason.trim() === '' ? {} : { reason: reason.trim() };
        if (await put('remove', `${endpoints.detail}/remove`, body, `${m.address} was removed from the council. Its record is kept.`, 'POST')) {
            setConfirming(false);
        }
    };

    return (
        <Card data-testid="member-owner-controls">
            <Card.Body className="space-y-4">
                <Heading as="h2" size="lg" weight="semibold">
                    Owner controls
                </Heading>
                <div className="flex flex-wrap items-end gap-3">
                    <NumberField
                        data-field="member-weight"
                        id="council-member-weight"
                        label="Vote weight"
                        description="0 is advisory: recorded and shown, never blocking. Applies from the next round close."
                        min={0}
                        step={1}
                        value={weight}
                        onValueChange={setWeight}
                    />
                    <Button data-action="save-member-weight" onClick={saveWeight} loading={busy === 'weight'} disabled={busy !== null} id="council-save-weight">
                        Save weight
                    </Button>
                </div>
                <Select
                    data-field="member-silence-policy"
                    id="council-member-policy"
                    label="On silence"
                    value={m.silence_policy}
                    onValueChange={(value) => void savePolicy(value)}
                    list={POLICIES}
                />
                <div className="space-y-3">
                    <Input data-field="project-name" id="council-project-name" label="Project name" value={name} onValueChange={setName} maxLength={200} />
                    <Textarea data-field="project-description" id="council-project-description" label="Project description" value={description} onValueChange={setDescription} minRows={3} />
                    <Input data-field="project-url" id="council-project-url" label="Project URL (http or https)" value={url} onValueChange={setUrl} error={urlError} placeholder="https://github.com/..." />
                    <Button data-action="save-member-project" onClick={saveProject} loading={busy === 'project'} disabled={busy !== null || urlError !== undefined} id="council-save-project">
                        Save project
                    </Button>
                </div>
                {!m.removed_at && (
                    <div className="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-testid="member-remove">
                        <Text size="sm" color="muted">
                            Removing a member keeps its row, voting record and audit history. It gets no new petitions, leaves the census check, is not
                            re-added by the seeder, and its petitions in open rounds are withdrawn.
                        </Text>
                        {!confirming ? (
                            <Button data-action="remove-member" onClick={() => setConfirming(true)} color="red" variant="ghost" disabled={busy !== null} id="council-remove-member">
                                Remove from council
                            </Button>
                        ) : (
                            <Callout color="red" data-testid="member-remove-confirm">
                                <div className="space-y-3">
                                    <Text size="sm">Remove {m.address} from the council? This cannot be undone from the lab.</Text>
                                    <Textarea data-field="remove-reason" id="council-remove-reason" label="Reason (optional, recorded in the audit log)" value={reason} onValueChange={setReason} minRows={2} />
                                    <div className="flex flex-wrap gap-2">
                                        <Button data-action="confirm-remove-member" onClick={() => void remove()} color="red" loading={busy === 'remove'} disabled={busy !== null} id="council-confirm-remove">
                                            Confirm removal
                                        </Button>
                                        <Button data-action="cancel-remove-member" onClick={() => setConfirming(false)} variant="ghost" disabled={busy !== null} id="council-cancel-remove">
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            </Callout>
                        )}
                    </div>
                )}
            </Card.Body>
        </Card>
    );
}
