import { useState } from 'react';
import { type ConsumerRow, type MemberRow, refusal, send } from './api';
import { label } from './tone';

/**
 * Inviting an agent onto the council (owner only, 0.9.0): the one copy of the
 * form's logic. The Members page draws the form (0.9.2; it was on the council
 * page before). Plain `.ts` like every part, so a host's `*.tsx` page glob
 * never mistakes it for a page.
 */

/** The invite API's answer: 201 a new member, 200 `invited: false` for a current one. */
export type InviteAnswer = { invited: boolean; member: MemberRow; consumer: ConsumerRow };

export type InviteFields = { address: string; weight: number | null; policy: string; project: string };

const EMPTY: InviteFields = { address: '', weight: 1, policy: 'escalate', project: '' };

/** name@workspace, as the API checks it. The server is the judge; this only words the mistake early. */
export const ADDRESS = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}@[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/;

/** What an invitation answer means, in words. There is no accept/decline: only the census request and its answer. */
export function inviteMessage(answer: InviteAnswer): string {
    const m = answer.member;
    if (!answer.invited) return `${m.address} is already a member. Nothing was changed; set its weight or policy on its own page.`;
    const request = m.census_request;

    return `Invited ${m.address} (weight ${m.weight}, ${label(m.silence_policy)}). ${
        request === null
            ? 'No census request was queued.'
            : `Its invitation, a census request, is ${request.delivery === 'sent' ? 'sent' : 'queued for the clerk to send over AgentInbox'}.`
    } It is petitioned on nothing until it answers with its packages.`;
}

export type Invite = {
    open: boolean;
    setOpen: (open: boolean | ((open: boolean) => boolean)) => void;
    fields: InviteFields;
    update: <K extends keyof InviteFields>(key: K, value: InviteFields[K]) => void;
    busy: boolean;
    submit: () => Promise<void>;
};

/**
 * The invite form's state and its submit. The API is the judge of every field;
 * the step-up ceremony runs inside send() through the host's handler. `say`
 * shows the outcome in words; `onInvited` gets every accepted answer (a new
 * member, or `invited: false` for a current one).
 */
export function useInvite(endpoint: string, onInvited: (answer: InviteAnswer) => void, say: (message: string) => void): Invite {
    const [open, setOpen] = useState(false);
    const [fields, setFields] = useState<InviteFields>(EMPTY);
    const [busy, setBusy] = useState(false);

    const update = <K extends keyof InviteFields>(key: K, value: InviteFields[K]) => setFields((v) => ({ ...v, [key]: value }));

    const submit = async () => {
        const address = fields.address.trim();
        if (!ADDRESS.test(address)) {
            say('The address is name@workspace, as AgentInbox shows it (for example helper@my-workspace).');
            return;
        }
        if (fields.weight === null || !Number.isInteger(fields.weight) || fields.weight < 0) {
            say('The weight is a whole number, 0 or more. 0 is advisory.');
            return;
        }
        setBusy(true);
        const answer = await send<InviteAnswer>('POST', endpoint, {
            address,
            weight: fields.weight,
            silence_policy: fields.policy,
            ...(fields.project.trim() !== '' ? { project_name: fields.project.trim() } : {}),
        });
        setBusy(false);
        if (answer.ok) {
            onInvited(answer.json);
            setOpen(false);
            setFields(EMPTY);
        }
        say(answer.ok ? inviteMessage(answer.json) : refusal(answer));
    };

    return { open, setOpen, fields, update, busy, submit };
}
