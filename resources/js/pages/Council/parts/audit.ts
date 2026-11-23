import type { Detail } from './api';
import { label, severityTone, stateTone, verdictTone } from './tone';

/**
 * The Audit tab's words: one audit event as a title, a one-line summary and
 * readable facts. Pure, and read-only over the event. What it leaves out of
 * the facts stays in `rawAuditRecord()`, the whole event, which the page keeps
 * behind its own disclosure. Plain `.ts` like every part, so a host's `*.tsx`
 * page glob never mistakes it for a page.
 */

export type AuditEvent = Detail['audit'][number];
export type AuditGroup = 'decision' | 'vote' | 'system';
export type AuditFilter = 'all' | AuditGroup;
export type AuditTone = ReturnType<typeof stateTone>;
export type AuditFact = { label: string; value: string };

export type AuditView = {
    title: string;
    summary: string;
    /** The Badge's word. */
    status: string;
    tone: AuditTone;
    group: AuditGroup;
    /** Routine plumbing (a notice sent or skipped), drawn quieter than the rest. */
    quiet: boolean;
    facts: AuditFact[];
};

export const AUDIT_FILTERS: { value: AuditFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'decision', label: 'Decisions' },
    { value: 'vote', label: 'Votes' },
    { value: 'system', label: 'System' },
];

type Data = Record<string, unknown>;
type Described = Omit<AuditView, 'quiet' | 'facts'> & { quiet?: boolean; facts: (AuditFact | null)[] };

const SUMMARY_MAX = 140;
const NOT_RECORDED = 'not recorded';

/** Names whose values are credential material: never in a summary or a generic fact. */
const SECRET_NAME = /signature|client_?data|authenticator|public_?key|challenge|user_?handle|credential|assertion|token|secret|password/i;
/** A long unbroken base64-like value: opaque to a reader, and possibly a credential. */
const OPAQUE_VALUE = /^[A-Za-z0-9+/_=-]{32,}$/;

const record = (value: unknown): Data => (typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Data) : {});
const text = (value: unknown): string | null => (typeof value === 'string' && value.trim() !== '' ? value : typeof value === 'number' ? String(value) : null);
const clip = (value: string, max = SUMMARY_MAX): string => (value.length > max ? `${value.slice(0, max - 3)}...` : value);
const plural = (n: number, one: string, many = `${one}s`): string => `${n} ${n === 1 ? one : many}`;
const word = (value: unknown): string | null => (typeof value === 'string' ? text(value) : null);
const sentence = (value: string): string => (/[.!?]$/.test(value) ? value : `${value}.`);

/** `moic@moic` reads as `moic`; `owner:a@b` as `a`. The full address stays in the row's actor. */
function who(value: unknown): string {
    const address = text(value);
    if (address === null) return 'unknown';

    return address.replace(/^owner:/, '').split('@')[0] || address;
}

function display(value: unknown): string | null {
    if (value === null || value === undefined) return null;
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    if (Array.isArray(value)) {
        if (value.length === 0) return 'none';

        return value.every((v) => typeof v === 'string' || typeof v === 'number') ? value.join(', ') : `${plural(value.length, 'entry', 'entries')}, in the raw record`;
    }

    return `${plural(Object.keys(record(value)).length, 'field')}, in the raw record`;
}

function fact(name: string, value: unknown): AuditFact | null {
    const shown = display(value);

    return shown === null ? null : { label: name, value: shown };
}

const coded = (name: string, value: unknown): AuditFact | null => fact(name, typeof value === 'string' ? label(value) : value);

/** A flag the host may not have recorded: absent is not the same answer as false. */
function flag(name: string, value: unknown): AuditFact {
    return { label: name, value: value === true ? 'yes' : value === false ? 'no' : NOT_RECORDED };
}

const because = (d: Data, otherwise: string): string => {
    const reason = text(d.reason) ?? text(d.note);

    // A code reads as words; free text is quoted as written.
    return reason === null ? otherwise : `Reason: ${clip(/^[a-z0-9_]+$/.test(reason) ? label(reason) : reason)}`;
};

const STEP_UP_VERBS: Record<string, string> = {
    'approve-initiation': 'approved',
    'reject-initiation': 'rejected',
    override: 'confirmed an override',
    revoke: 'revoked',
    'change-silence-policy': 'changed a silence policy',
    'consumer-silence-policy': 'changed a silence policy',
    'member-weight': 'changed a weight',
    'member-project': 'changed a project',
    'member-remove': 'removed a member',
    'member-invite': 'invited a member',
    'blocking-threshold': 'changed the blocking threshold',
};

const actionWords = (action: string | null): string => (action === null ? 'an action' : action.replace(/[-_]/g, ' '));

function stepUp(d: Data): Described {
    const evidence = record(d.evidence);
    const action = word(d.action);
    const via = clip(text(evidence.method) ?? 'step-up', 40);
    const verb = action !== null && Object.hasOwn(STEP_UP_VERBS, action) ? STEP_UP_VERBS[action] : `confirmed ${actionWords(action)}`;
    const target = text(d.ref) ?? text(d.address);
    const unverified = evidence.user_verified === false;

    return {
        title: `Owner ${verb} with ${via}`,
        summary: `Verified ${via} for ${actionWords(action)}${target ? ` on ${target}` : ''}${unverified ? ', without user verification' : ''}.`,
        status: 'verified',
        tone: unverified ? 'amber' : 'green',
        group: 'decision',
        facts: [
            { label: 'Method', value: text(evidence.method) ?? NOT_RECORDED },
            { label: 'Action', value: action === null ? NOT_RECORDED : actionWords(action) },
            fact('Change', text(d.ref)),
            fact('Member', text(d.address)),
            { label: 'Verified at', value: text(evidence.verified_at) ?? NOT_RECORDED },
            { label: 'Verified by', value: text(evidence.verified_by) ?? NOT_RECORDED },
            flag('User present', evidence.user_present),
            flag('User verified', evidence.user_verified),
        ],
    };
}

function stepUpRefused(d: Data): Described {
    const action = word(d.action);
    const target = text(d.ref) ?? text(d.address);

    return {
        title: 'Owner step-up refused',
        summary: `Refused for ${actionWords(action)}${target ? ` on ${target}` : ''}: ${clip(label(text(d.reason) ?? 'no reason recorded'))}. The action did not run.`,
        status: 'refused',
        tone: 'red',
        group: 'decision',
        facts: [
            { label: 'Action', value: action === null ? NOT_RECORDED : actionWords(action) },
            fact('Change', text(d.ref)),
            fact('Member', text(d.address)),
            coded('Reason', d.reason),
            fact('Method', text(record(d.evidence).method)),
        ],
    };
}

function decided(state: string, title: (actor: string) => string, otherwise: string) {
    return (d: Data, e: AuditEvent): Described => ({
        title: title(e.actor),
        summary: because(d, otherwise),
        status: label(state),
        tone: stateTone(state),
        group: 'decision',
        facts: [fact('Reason', d.reason)],
    });
}

const byWhom = (actor: string): string => (actor === 'tally' ? 'the tally' : actor.startsWith('owner:') ? 'the owner' : who(actor));

function notice(status: string, title: string, tone: AuditTone, quiet: boolean) {
    return (d: Data): Described => {
        const code = text(d.status);

        return {
            title,
            summary: `Notice about ${label(text(d.event) ?? 'an event')}${text(d.ref) ? ` for ${text(d.ref)}` : ''}${code ? ` (${label(code)})` : ''}.`,
            status,
            tone,
            group: 'system',
            quiet,
            facts: [coded('Event', d.event), fact('Change', d.ref), coded('Result', d.status), fact('Retry after (seconds)', d.retry_after)],
        };
    };
}

function petition(status: (d: Data) => string, title: (to: string) => string, summary: (d: Data) => string) {
    return (d: Data): Described => ({
        title: title(who(d.to)),
        summary: summary(d),
        status: status(d),
        tone: 'zinc',
        group: 'system',
        facts: [fact('To', d.to), fact('Petition', d.petition), fact('Round', d.round), coded('Tier', d.tier), coded('Because', d.because), coded('Outcome', d.outcome), fact('Counted', d.counted)],
    });
}

function countsLine(counts: unknown): string {
    const parts = Object.entries(record(counts))
        .filter(([, n]) => typeof n === 'number' && n > 0)
        .map(([k, n]) => `${n} ${label(k)}`);

    return parts.length > 0 ? parts.join(', ') : 'No votes counted';
}

const HANDLERS = new Map<string, (d: Data, e: AuditEvent) => Described>([
    ['change.proposed', (d, e) => ({
        title: `Change proposed by ${who(e.actor)}`,
        summary: `${text(d.ref) ?? 'A Change'} ${d.hitl === true ? 'waits for the owner before any petition is sent' : 'opens its first round'}.`,
        status: 'proposed',
        tone: 'blue',
        group: 'system',
        facts: [fact('Change', d.ref), coded('Source', d.source), fact('Whitelisted', d.whitelisted), fact('Waits for the owner', d.hitl), fact('Classes', d.classes), fact('Packages', d.packages)],
    })],
    ['initiation.approved', (d) => ({ title: 'Owner approved the initiation', summary: text(d.note) ? `Note: ${clip(text(d.note) as string)}` : 'The consumers are petitioned next.', status: 'approved', tone: 'green', group: 'decision', facts: [fact('Note', d.note)] })],
    ['initiation.rejected', (d) => ({ title: 'Owner rejected the initiation', summary: because(d, 'No reason recorded.'), status: 'rejected', tone: 'red', group: 'decision', facts: [fact('Reason', d.reason)] })],
    ['approach.revised', (d, e) => ({ title: `Approach revised by ${who(e.actor)}`, summary: `The revised approach goes to round ${text(d.round) ?? '?'}.`, status: 'revised', tone: 'violet', group: 'system', facts: [fact('Round', d.round), fact('Approach digest', d.digest)] })],
    ['round.opened', (d) => ({ title: `Round ${text(d.round) ?? '?'} opened`, summary: text(d.closes_at) ? `Closes ${text(d.closes_at)}.` : 'The round is open.', status: 'open', tone: 'blue', group: 'system', facts: [fact('Round', d.round), fact('Closes at', d.closes_at), fact('Approach digest', d.digest)] })],
    ['round.extended', (d) => ({ title: `Round ${text(d.round) ?? '?'} extended`, summary: `${text(d.until) ? `Now closes ${text(d.until)}` : 'Deadline extended'}: ${plural(Array.isArray(d.silent) ? d.silent.length : 0, 'required voter')} still silent.`, status: 'extended', tone: 'amber', group: 'system', facts: [fact('Round', d.round), fact('Until', d.until), fact('Silent', d.silent)] })],
    ['round.closed', (d) => {
        const outcome = text(d.outcome) ?? 'closed';

        return {
            title: `Round ${text(d.round) ?? '?'} closed: ${label(outcome)}`,
            summary: `${countsLine(d.counts)}. Blocking weight ${text(d.blocking_weight) ?? '?'} of threshold ${text(d.threshold) ?? '?'}.`,
            status: label(outcome),
            tone: stateTone(outcome),
            group: 'vote',
            facts: [fact('Round', d.round), coded('Outcome', d.outcome), coded('Reason', d.reason), { label: 'Votes', value: countsLine(d.counts) }, fact('Blocking weight', d.blocking_weight), fact('Threshold', d.threshold)],
        };
    }],
    ['petition.queued', petition(() => 'queued', (to) => `Petition queued for ${to}`, (d) => `${label(text(d.tier) ?? 'A')} voter, round ${text(d.round) ?? '?'}; waits for the clerk to send it.`)],
    ['petition.superseded', petition(() => 'superseded', (to) => `Petition to ${to} superseded`, (d) => `Never sent: ${label(text(d.because) ?? 'no longer needed')}.`)],
    ['petition.catch_up', petition((d) => label(text(d.outcome) ?? 'catch up'), (to) => `Catch-up petition for ${to}`, (d) => `Silent voter under proceed-and-notify, recorded as ${label(text(d.outcome) ?? 'unknown')}.`)],
    ['petition.withdrawn', petition(() => 'withdrawn', (to) => `Petition to ${to} withdrawn`, (d) => `Not counted: ${label(text(d.because) ?? 'withdrawn')}.`)],
    ['vote.recorded', (d, e) => {
        const verdict = text(d.verdict) ?? 'unknown';
        const flags = typeof d.flags === 'number' ? d.flags : 0;

        return {
            title: `Vote: ${verdict} from ${who(e.actor)}`,
            summary: `Round ${text(d.round) ?? '?'}${text(d.worst) ? `, worst impact ${text(d.worst)}` : ''}${flags > 0 ? `, ${plural(flags, 'injection flag')}` : ''}.`,
            status: verdict,
            tone: verdictTone(verdict),
            group: 'vote',
            facts: [fact('Verdict', d.verdict), fact('Round', d.round), fact('Vote', d.vote), fact('Petition', d.petition), fact('Worst severity', d.worst), fact('Injection flags', d.flags)],
        };
    }],
    ['vote.late', (d, e) => {
        const verdict = text(d.verdict) ?? 'unknown';

        return { title: `Late vote: ${verdict} from ${who(e.actor)}`, summary: 'Arrived after its round closed.', status: verdict, tone: verdictTone(verdict), group: 'vote', facts: [fact('Verdict', d.verdict), fact('Vote', d.vote), fact('Petition', d.petition)] };
    }],
    ['vote.late_conflict', (d) => {
        const severity = text(d.severity) ?? 'conflict';

        return { title: `Late conflict on vote #${text(d.vote) ?? '?'}`, summary: `A catching-up consumer reported a ${severity} conflict after the round closed.`, status: severity, tone: severityTone(severity), group: 'vote', facts: [fact('Severity', d.severity), fact('Vote', d.vote), fact('Petition', d.petition)] };
    }],
    ['change.approved', decided('approved', (a) => `Approved by ${byWhom(a)}`, 'The Change is approved.')],
    ['change.rejected', decided('rejected', (a) => `Rejected by ${byWhom(a)}`, 'The Change is rejected.')],
    ['change.revoked', decided('revoked', (a) => `Approval revoked by ${byWhom(a)}`, 'The approval is revoked.')],
    ['change.escalated', decided('escalated', () => 'Escalated to the owner', 'The owner decides next.')],
    ['change.revision_required', decided('revision_required', () => 'Revision required', 'The author revises the approach for round 2.')],
    ['change.withdrawn', (_d, e) => ({ title: `Withdrawn by ${who(e.actor)}`, summary: 'The Change was withdrawn.', status: 'withdrawn', tone: 'zinc', group: 'decision', facts: [fact('By', e.actor)] })],
    ['owner.override', (d) => {
        const decision = text(d.decision) ?? 'unknown';

        return {
            title: `Owner override: ${decision}`,
            summary: because(d, 'No reason recorded.'),
            status: decision,
            tone: decision === 'approve' ? 'green' : decision === 'reject' ? 'red' : 'violet',
            group: 'decision',
            facts: [fact('Decision', d.decision), fact('Reason', d.reason), coded('From state', d.from_state), fact('Acknowledged conflict votes', d.acknowledged)],
        };
    }],
    ['owner.step_up', stepUp],
    ['owner.step_up_refused', stepUpRefused],
    ['notify.sent', notice('sent', 'Owner notified', 'zinc', true)],
    ['notify.skipped', notice('skipped', 'Owner notice skipped', 'zinc', true)],
    ['notify.failed', notice('failed', 'Owner notice failed', 'red', false)],
    ['change.silence_policy', (d) => ({ title: `Silence policy set for ${who(d.address)} on this Change`, summary: text(d.policy) ? `On this Change: ${label(text(d.policy) as string)}.` : 'The override on this Change was cleared.', status: text(d.policy) ? label(text(d.policy) as string) : 'cleared', tone: 'violet', group: 'decision', facts: [fact('Member', d.address), coded('Policy', d.policy)] })],
    ['consumer.silence_policy', (d) => ({ title: `Silence policy changed for ${who(d.address)}`, summary: `From ${label(text(d.from) ?? '?')} to ${label(text(d.to) ?? '?')}.`, status: label(text(d.to) ?? 'changed'), tone: 'violet', group: 'decision', facts: [fact('Member', d.address), coded('From', d.from), coded('To', d.to)] })],
    ['consumer.weight', (d) => ({ title: `Weight changed for ${who(d.address)}`, summary: `From ${text(d.from) ?? '?'} to ${text(d.to) ?? '?'}.`, status: `weight ${text(d.to) ?? '?'}`, tone: 'violet', group: 'decision', facts: [fact('Member', d.address), fact('From', d.from), fact('To', d.to)] })],
    ['consumer.project', (d) => ({ title: `Project details changed for ${who(d.address)}`, summary: `Fields: ${display(d.fields) ?? 'none'}.`, status: 'updated', tone: 'violet', group: 'decision', facts: [fact('Member', d.address), fact('Fields', d.fields)] })],
    ['consumer.invited', (d) => ({ title: `${who(d.address)} invited to the council`, summary: `Weight ${text(d.weight) ?? '?'}, ${label(text(d.silence_policy) ?? 'default policy')}.`, status: 'invited', tone: 'violet', group: 'decision', facts: [fact('Member', d.address), fact('Weight', d.weight), coded('Silence policy', d.silence_policy), fact('Project fields', d.fields)] })],
    ['consumer.removed', (d) => ({ title: `${who(d.address)} removed from the council`, summary: because(d, 'No reason recorded.'), status: 'removed', tone: 'red', group: 'decision', facts: [fact('Member', d.address), fact('Reason', d.reason), fact('Withdrawn petitions', d.withdrawn)] })],
    ['council.blocking_threshold', (d) => ({ title: 'Blocking threshold changed', summary: `From ${text(d.from) ?? '?'} to ${text(d.to) ?? '?'}.`, status: `threshold ${text(d.to) ?? '?'}`, tone: 'violet', group: 'decision', facts: [fact('From', d.from), fact('To', d.to)] })],
    ['census.requested', (d) => ({ title: `Census requested from ${who(d.to)}`, summary: 'Queued for the clerk to send.', status: 'requested', tone: 'zinc', group: 'system', facts: [fact('To', d.to), fact('Request', d.request)] })],
    ['census.applied', (d) => {
        const size = (v: unknown): number => (Array.isArray(v) ? v.length : Object.keys(record(v)).length);

        return {
            title: `Census applied from ${who(d.address)}`,
            summary: `${size(d.added)} added, ${size(d.removed)} removed, ${size(d.changed)} changed, ${size(d.dropped)} dropped.`,
            status: 'applied',
            tone: 'green',
            group: 'system',
            facts: [fact('Member', d.address), fact('Request', d.request), fact('Reply', d.reply), fact('Added', d.added), fact('Removed', d.removed), fact('Changed', d.changed), fact('Dropped', d.dropped), fact('Apps', d.apps), fact('Injection flags', d.flags)],
        };
    }],
]);

/** Every type this helper words; a test holds it against what `src/` can write. */
export const AUDIT_TYPES: string[] = [...HANDLERS.keys()];

/** A type this version does not know: its readable scalars, and nothing that could be credential material. */
function generic(d: Data, e: AuditEvent): Described {
    const facts = Object.entries(d)
        .filter(([key, value]) => !SECRET_NAME.test(key) && (typeof value === 'number' || typeof value === 'boolean' || (typeof value === 'string' && value !== '' && !OPAQUE_VALUE.test(value))))
        .map(([key, value]) => fact(label(key), value));
    const words = String(e.type).replace(/[._-]+/g, ' ').trim() || 'event';
    const summary = facts
        .slice(0, 3)
        .map((f) => `${f?.label}: ${f?.value}`)
        .join(', ');

    return {
        title: words.charAt(0).toUpperCase() + words.slice(1),
        summary: summary === '' ? 'No readable details; see the raw record.' : clip(summary),
        status: 'event',
        tone: 'zinc',
        group: 'system',
        facts,
    };
}

export function describeAuditEvent(event: AuditEvent): AuditView {
    const data = record(event.data);
    const described = (HANDLERS.get(event.type) ?? generic)(data, event);

    return {
        ...described,
        summary: sentence(clip(described.summary)),
        quiet: described.quiet ?? false,
        facts: [...described.facts.filter((f): f is AuditFact => f !== null), { label: 'Event type', value: String(event.type) }],
    };
}

export function filterAuditEvents(events: AuditEvent[], filter: AuditFilter): AuditEvent[] {
    return filter === 'all' ? events : events.filter((event) => describeAuditEvent(event).group === filter);
}

/** The whole event, unchanged, for the "Raw record" disclosure and its copy button. */
export function rawAuditRecord(event: AuditEvent): string {
    return JSON.stringify(event, null, 2);
}
