import { useEffect, useRef, useState } from 'react';

/**
 * Wire types for the council API, a slow visibility-gated poll, and one send
 * helper. Plain .ts (no JSX) on purpose: a host's `./Pages/**\/*.tsx` glob
 * then never mistakes this file for a page.
 */

export type Package = { ecosystem: string; name: string; from?: string; to?: string };

export type Summary = {
    ref: string;
    title: string;
    state: string;
    classes: string[];
    packages: Package[];
    initiation_source: string;
    initiator: string;
    whitelisted: boolean;
    current_round: number | null;
    deadline: string | null;
    petitions: { total: number; answered: number; conflicts: number; queued: number };
    approved_unopposed: boolean;
    approved_with_notes: boolean;
    late_conflict: boolean;
    /** A round had blocking conflicts whose weight did not reach the threshold. */
    blocking_below_threshold: boolean;
    decided_by: string | null;
    updated_at: string | null;
};

export type Impact = { app: string; paths: string[]; severity: 'blocker' | 'major' | 'minor'; note: string };

export type Vote = {
    id: number;
    from_address: string;
    genie_message_id: string;
    status: string;
    problem: string | null;
    verdict: 'approve' | 'conflict' | 'abstain' | null;
    impact: Impact[];
    alternative: string | null;
    reason: string | null;
    flags: string[];
    raw_text: string;
    received_at: string | null;
};

export type PetitionRow = {
    id: number;
    agent_address: string;
    tier: 'required' | 'informed';
    why: string;
    delivery: string;
    delivery_detail: string | null;
    outcome: string | null;
    catch_up: boolean;
    /** Set when the member was removed while this round was open: neither a vote nor a silence. */
    withdrawn_at: string | null;
    effective_silence_policy: Policy;
    /** The member's weight now. A closed round's tally keeps the weights it used. */
    weight: number;
    votes: Vote[];
};

/** The threshold arithmetic: recorded by the tally, or `live` for an open round. */
export type Weighing = {
    blocking_weight: number;
    threshold: number;
    blocks: boolean;
    blocking: number[];
    below_threshold: number[];
    advisory: number[];
    live: boolean;
};

export type RoundRow = {
    number: number;
    approach: string;
    approach_digest: string;
    opened_at: string | null;
    closes_at: string | null;
    extended_until: string | null;
    closed_at: string | null;
    outcome: string | null;
    tally: { counts?: Record<string, number>; reason?: string; escalate?: string[]; catch_up?: string[]; weights?: Record<string, number> } | null;
    /** Null for a round decided before weights existed. */
    weighing: Weighing | null;
    petitions: PetitionRow[];
};

export type Finding = {
    class: string;
    subject: string;
    before: string | null;
    after: string | null;
    severity: string;
    file: string | null;
    line: number | null;
    note: string | null;
    waived: { kind: string; reason: string } | null;
    fingerprint: string;
};

export type Detail = {
    change: Summary & {
        summary: string;
        approach: string;
        evidence: { findings?: Finding[]; detector?: { version: string } } & Record<string, unknown>;
        links: Record<string, unknown>;
        pros: string | null;
        cons: string | null;
        silence_overrides: Record<string, Policy>;
        decision_reason: string | null;
        decided_at: string | null;
    };
    rounds: RoundRow[];
    open_conflicts: number[];
    decisions: { id: number; outcome: string; decided_by: string; reason: string | null; acknowledged: number[]; decided_at: string | null }[];
    audit: { seq: number; at: string; actor: string; type: string; data: Record<string, unknown>; hash: string }[];
    owner_actions: string[];
};

export type Policy = 'escalate' | 'proceed_and_notify';

/** The way back to the host app (config council.host_url / host_label); null when the host hides it. */
export type HostLink = { url: string; label: string } | null;

export type ConsumerRow = {
    address: string;
    packages: Package[];
    watches: Package[];
    silence_policy: Policy;
    weight: number;
    whitelisted: boolean;
    internal: boolean;
    source: string;
    census_at: string | null;
    notes: string | null;
    removed_at: string | null;
};

export type MemberStats = {
    petitions: number;
    cast: number;
    approve: number;
    conflict: number;
    abstain: number;
    missed: number;
    pending: number;
    /** Open petitions withdrawn when the member was removed. */
    withdrawn: number;
    last_vote_at: string | null;
};

export type CensusRequestState = { id: number; delivery: 'queued' | 'sent'; queued_at: string; sent_at: string | null };

/** A council member (a consumer row). Every string here is agent-supplied: render it as text. */
export type MemberRow = {
    address: string;
    name: string;
    workspace: string | null;
    project_name: string | null;
    project_description: string | null;
    /** Absolute http or https, or null. */
    project_url: string | null;
    weight: number;
    silence_policy: Policy;
    whitelisted: boolean;
    internal: boolean;
    /** Where `packages` came from: census (the member's own lockfiles), declared or inferred (hand-written). */
    source: string;
    census_at: string | null;
    /** An outstanding census request (queued for the clerk, or sent and unanswered), else null. */
    census_request: CensusRequestState | null;
    reachable: boolean | null;
    notes: string | null;
    /** A census row carries the installed `version`; a hand-written row a `constraint`. */
    packages: (Package & { constraint?: string; version?: string })[];
    watches: Package[];
    package_count: number;
    stats: MemberStats;
    /** When the owner removed this member from the council; null for a current member. */
    removed_at: string | null;
};

export type RecordRow = {
    petition: number;
    change: { ref: string | null; title: string | null; state: string | null };
    round: number;
    tier: 'required' | 'informed';
    delivery: string;
    outcome: string | null;
    verdict: string | null;
    vote_status: string | null;
    late: boolean;
    reason: string | null;
    impact: Impact[];
    severity: string | null;
    queued_at: string | null;
    sent_at: string | null;
    replied_at: string | null;
    withdrawn: boolean;
};

export type Threshold = { value: number; source: 'owner' | 'config' };

export type MemberDetail = { member: MemberRow; record: RecordRow[]; blocking_threshold: Threshold };

export type Answer<T> = { ok: boolean; status: number; json: T & { error?: { code: string; [key: string]: unknown } } };

function xsrf(): Record<string, string> {
    if (typeof document === 'undefined') return {};
    const meta = document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')?.content;
    if (meta) return { 'X-CSRF-TOKEN': meta };
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {};
}

/** Never throws: a request that did not arrive is status 0 with an error code. */
export async function send<T>(method: 'GET' | 'POST' | 'PUT', url: string, body?: unknown): Promise<Answer<T>> {
    try {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', ...xsrf() }),
            },
            body: method === 'GET' || body === undefined ? undefined : JSON.stringify(body),
        });
        let json: unknown = {};
        try {
            json = await res.json();
        } catch {
            json = { error: { code: `http_${res.status}` } };
        }

        return { ok: res.ok, status: res.status, json: json as Answer<T>['json'] };
    } catch {
        return { ok: false, status: 0, json: { error: { code: 'unreachable' } } as Answer<T>['json'] };
    }
}

/**
 * Polls `url` slowly, and not at all while the tab is hidden. A lab is served by
 * ONE php-cgi worker that 502s under aggressive polling, so the floor is 15s
 * whatever the host asks for.
 */
export function usePoll<T>(url: string, initial: T, pollMs: number): [T, (next: T) => void, string | null] {
    const [data, setData] = useState<T>(initial);
    const [error, setError] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const every = Math.max(15_000, pollMs);

    useEffect(() => {
        let cancelled = false;
        const tick = async () => {
            if (document.visibilityState === 'visible') {
                const answer = await send<T>('GET', url);
                if (cancelled) return;
                if (answer.ok) {
                    setData(answer.json);
                    setError(null);
                } else {
                    setError(answer.json.error?.code ?? `http_${answer.status}`);
                }
            }
            if (!cancelled) timer.current = setTimeout(tick, every);
        };
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                if (timer.current) clearTimeout(timer.current);
                void tick();
            }
        };
        timer.current = setTimeout(tick, every);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            cancelled = true;
            if (timer.current) clearTimeout(timer.current);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [url, every]);

    return [data, setData, error];
}
