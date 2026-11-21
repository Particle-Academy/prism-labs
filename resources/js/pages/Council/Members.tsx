import { Head, Link } from '@inertiajs/react';
import { Badge, Button, Callout, Card, Heading, NumberField, StatList, Table, Text } from '@particle-academy/react-fancy';
import { useMemo, useState } from 'react';
import { type HostLink, type MemberRow, send, refusal, type Threshold, usePoll } from './parts/api';
import { compare, navVariant, nextSort, type Sort } from './parts/nav';
import { censusRequestLabel, label, sourceTone } from './parts/tone';

type Props = {
    members: MemberRow[];
    blocking_threshold: Threshold;
    /** Members the owner removed: kept with their record, never petitioned. */
    former_members?: MemberRow[];
    pollMs: number;
    host: HostLink;
    /** Draw the Changes/Members row; false when the host renders the pages inside its own navigation. */
    showNav?: boolean;
    /** Whether the hook allows `owner` for this viewer. Display only: the API asks again. */
    canSetPolicy: boolean;
    endpoints: { index: string; members: string; api: string; threshold: string; show: string };
};

type Key = 'name' | 'project' | 'weight' | 'policy' | 'packages' | 'cast' | 'approve' | 'conflict' | 'abstain' | 'missed' | 'last_vote' | 'census';

const SORTERS: Record<Key, (m: MemberRow) => string | number | null> = {
    name: (m) => m.name,
    project: (m) => m.project_name ?? m.workspace,
    weight: (m) => m.weight,
    policy: (m) => m.silence_policy,
    packages: (m) => m.package_count,
    cast: (m) => m.stats.cast,
    approve: (m) => m.stats.approve,
    conflict: (m) => m.stats.conflict,
    abstain: (m) => m.stats.abstain,
    missed: (m) => m.stats.missed,
    last_vote: (m) => m.stats.last_vote_at,
    census: (m) => m.census_at,
};

/**
 * Council/Members: every council member (consumer row) with its weight, silence
 * policy, declared packages and voting counts, sortable, polled slowly. Members
 * the owner removed are listed apart, under Former members. Built only from
 * Fancy components; every member string is rendered as text.
 */
export default function Members({ members: initial, blocking_threshold, former_members = [], pollMs, host, showNav = true, canSetPolicy, endpoints }: Props) {
    const [data, setData, pollError] = usePoll<{ members: MemberRow[]; blocking_threshold: Threshold; former_members?: MemberRow[] }>(
        endpoints.api,
        { members: initial, blocking_threshold, former_members },
        pollMs,
    );
    const former = data.former_members ?? [];
    // Starts unsorted, like the Table's own state, so its arrow and this order agree.
    const [sort, setSort] = useState<Sort<Key>>({ key: null, dir: 'asc' });
    const [threshold, setThreshold] = useState<number | null>(blocking_threshold.value);
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const rows = useMemo(() => {
        if (sort.key === null) return data.members;
        const by = SORTERS[sort.key];
        const sorted = [...data.members].sort((a, b) => compare(by(a), by(b)) || a.address.localeCompare(b.address));

        return sort.dir === 'asc' ? sorted : sorted.reverse();
    }, [data.members, sort]);

    const column = (key: Key, text: string) => ({
        label: text,
        onClick: () => setSort((s) => nextSort(s, key)),
        'aria-sort': (sort.key === key ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none') as 'ascending' | 'descending' | 'none',
    });

    const saveThreshold = async () => {
        if (threshold === null || !Number.isInteger(threshold) || threshold < 1) {
            setMessage('The threshold is a whole number, 1 or more.');
            return;
        }
        setBusy(true);
        const answer = await send<{ blocking_threshold: Threshold }>('PUT', endpoints.threshold, { threshold });
        setBusy(false);
        if (answer.ok) {
            setData({ ...data, blocking_threshold: answer.json.blocking_threshold });
        }
        setMessage(answer.ok ? `Blocking threshold set to ${answer.json.blocking_threshold.value}.` : refusal(answer));
    };

    const totalWeight = data.members.filter((m) => !m.internal).reduce((n, m) => n + m.weight, 0);

    return (
        <>
            <Head title="Council members" />
            <div className="mx-auto max-w-7xl space-y-6 p-6" data-surface="council-members">
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
                    <div>
                        <Heading as="h1" size="2xl" weight="semibold">
                            Council members
                        </Heading>
                        <Text color="muted" size="sm">
                            The consumer agents petitioned on major changes. A blocking conflict blocks only when the weight of the members raising it
                            reaches the threshold. Weight 0 is advisory.
                        </Text>
                    </div>
                    <StatList
                        data-testid="council-members-stats"
                        items={[
                            { key: 'members', label: 'Members', value: data.members.length },
                            { key: 'weight', label: 'Voting weight', value: totalWeight },
                            { key: 'threshold', label: 'Blocking threshold', value: data.blocking_threshold.value },
                        ]}
                    />
                </div>

                {message && (
                    <Callout color="blue" dismissible onDismiss={() => setMessage(null)} data-testid="council-message">
                        {message}
                    </Callout>
                )}
                {pollError && (
                    <Callout color="red" data-testid="council-poll-error">
                        Could not refresh ({pollError}). Showing the last answer.
                    </Callout>
                )}

                {canSetPolicy && (
                    <Card data-testid="council-threshold">
                        <Card.Body className="flex flex-wrap items-end gap-3">
                            <NumberField
                                data-field="blocking-threshold"
                                id="council-blocking-threshold"
                                label="Blocking threshold"
                                description={`Combined weight a round's blocking conflicts need to block. Now ${data.blocking_threshold.value} (${data.blocking_threshold.source === 'owner' ? 'set by you' : 'the config default'}).`}
                                min={1}
                                step={1}
                                value={threshold}
                                onValueChange={setThreshold}
                            />
                            <Button data-action="save-blocking-threshold" onClick={() => void saveThreshold()} loading={busy} disabled={busy} id="council-save-threshold">
                                Save threshold
                            </Button>
                        </Card.Body>
                    </Card>
                )}

                <Card>
                    <Card.Body>
                        {rows.length === 0 ? (
                            <Text color="muted" data-testid="council-members-empty">
                                No members yet. Seed them with php artisan council:seed-consumers.
                            </Text>
                        ) : (
                            <Table className="overflow-x-auto" data-testid="council-members">
                                <Table.Head>
                                    <Table.Row>
                                        <Table.Column data-action="sort-name" {...column('name', 'Member')} sortKey="name" />
                                        <Table.Column data-action="sort-project" {...column('project', 'Project')} sortKey="project" />
                                        <Table.Column data-action="sort-weight" {...column('weight', 'Weight')} sortKey="weight" />
                                        <Table.Column data-action="sort-policy" {...column('policy', 'On silence')} sortKey="policy" />
                                        <Table.Column data-action="sort-packages" {...column('packages', 'Packages')} sortKey="packages" />
                                        <Table.Column data-action="sort-cast" {...column('cast', 'Votes')} sortKey="cast" />
                                        <Table.Column data-action="sort-approve" {...column('approve', 'Approve')} sortKey="approve" />
                                        <Table.Column data-action="sort-conflict" {...column('conflict', 'Conflict')} sortKey="conflict" />
                                        <Table.Column data-action="sort-abstain" {...column('abstain', 'Abstain')} sortKey="abstain" />
                                        <Table.Column data-action="sort-missed" {...column('missed', 'Missed')} sortKey="missed" />
                                        <Table.Column data-action="sort-last-vote" {...column('last_vote', 'Last vote')} sortKey="last_vote" />
                                        <Table.Column data-action="sort-census" {...column('census', 'Census')} sortKey="census" />
                                    </Table.Row>
                                </Table.Head>
                                <Table.Body>
                                    {rows.map((m) => (
                                        <Table.Row key={m.address} data-member={m.address}>
                                            <Table.Cell>
                                                <Link
                                                    href={`${endpoints.members}/${encodeURIComponent(m.address)}`}
                                                    className="font-medium text-violet-700 underline underline-offset-2 hover:text-violet-900 dark:text-violet-300 dark:hover:text-violet-100"
                                                    id={`council-member-${m.address}`}
                                                >
                                                    {m.name}
                                                </Link>
                                                <div className="text-xs text-zinc-500">{m.address}</div>
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {m.internal && (
                                                        <Badge color="zinc" size="sm">
                                                            internal
                                                        </Badge>
                                                    )}
                                                    {m.whitelisted && (
                                                        <Badge color="green" size="sm">
                                                            whitelisted
                                                        </Badge>
                                                    )}
                                                </div>
                                            </Table.Cell>
                                            <Table.Cell className="text-sm">
                                                <div>{m.project_name ?? '-'}</div>
                                                <div className="text-xs text-zinc-500">{m.workspace ?? ''}</div>
                                            </Table.Cell>
                                            <Table.Cell className="text-sm">
                                                <Badge color={m.weight === 0 ? 'zinc' : 'violet'} variant="soft" size="sm" data-weight={m.weight}>
                                                    {m.weight === 0 ? '0 (advisory)' : m.weight}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color={m.silence_policy === 'escalate' ? 'amber' : 'blue'} variant="soft" size="sm">
                                                    {label(m.silence_policy)}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell className="text-sm whitespace-nowrap">
                                                {m.package_count}{' '}
                                                <Badge color={sourceTone(m.source)} variant="soft" size="sm" data-source={m.source}>
                                                    {m.source}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell className="text-sm whitespace-nowrap">
                                                {m.stats.cast} of {m.stats.petitions}
                                                {m.stats.pending > 0 ? ` (${m.stats.pending} open)` : ''}
                                            </Table.Cell>
                                            <Table.Cell className="text-sm">{m.stats.approve}</Table.Cell>
                                            <Table.Cell className="text-sm">{m.stats.conflict}</Table.Cell>
                                            <Table.Cell className="text-sm">{m.stats.abstain}</Table.Cell>
                                            <Table.Cell className="text-sm">{m.stats.missed}</Table.Cell>
                                            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">{m.stats.last_vote_at ?? 'never'}</Table.Cell>
                                            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">
                                                <div>{m.census_at ?? 'never'}</div>
                                                {m.census_request && (
                                                    <Badge color="amber" variant="soft" size="sm" data-census-request={m.census_request.delivery}>
                                                        {censusRequestLabel(m.census_request)}
                                                    </Badge>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table>
                        )}
                    </Card.Body>
                </Card>

                {former.length > 0 && (
                    <Card>
                        <Card.Body className="space-y-3">
                            <Heading as="h2" size="lg" weight="semibold">
                                Former members
                            </Heading>
                            <Text color="muted" size="sm">
                                Removed from the council by the owner. Their voting record is kept; they get no new petitions.
                            </Text>
                            <Table className="overflow-x-auto" data-testid="council-former-members">
                                <Table.Head>
                                    <Table.Row>
                                        <Table.Cell header>Member</Table.Cell>
                                        <Table.Cell header>Removed</Table.Cell>
                                        <Table.Cell header>Votes</Table.Cell>
                                        <Table.Cell header>Withdrawn</Table.Cell>
                                        <Table.Cell header>Last vote</Table.Cell>
                                    </Table.Row>
                                </Table.Head>
                                <Table.Body>
                                    {former.map((m) => (
                                        <Table.Row key={m.address} data-member={m.address} data-removed={m.removed_at ?? ''}>
                                            <Table.Cell>
                                                <Link
                                                    href={`${endpoints.members}/${encodeURIComponent(m.address)}`}
                                                    className="font-medium text-zinc-700 underline underline-offset-2 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100"
                                                    id={`council-former-member-${m.address}`}
                                                >
                                                    {m.name}
                                                </Link>
                                                <div className="text-xs text-zinc-500">{m.address}</div>
                                            </Table.Cell>
                                            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">{m.removed_at ?? '-'}</Table.Cell>
                                            <Table.Cell className="text-sm whitespace-nowrap">
                                                {m.stats.cast} of {m.stats.petitions}
                                            </Table.Cell>
                                            <Table.Cell className="text-sm">{m.stats.withdrawn}</Table.Cell>
                                            <Table.Cell className="text-xs whitespace-nowrap text-zinc-500">{m.stats.last_vote_at ?? 'never'}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table>
                        </Card.Body>
                    </Card>
                )}
            </div>
        </>
    );
}
