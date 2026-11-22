import { Badge, Button, Callout, Card, Grid, Input, Select, Switch, Text } from '@particle-academy/react-fancy';
import { useCallback, useEffect, useRef, useState } from 'react';
import { LabShell } from '../../components/lab-shell';

/*
 * /lab/accounts: Claude accounts for the Lab's Claude launches, through prism-acp's claudeAccounts API.
 *
 * "Add account" opens the sign-in page in a new tab and polls the sign-in until it ends; no terminal is shown. The
 * Lab stores only a label, a directory and which account is active. Everything else on this page -- signed in or not,
 * method, billing -- is asked of the Claude CLI when you press Check, and never stored.
 */

type Login = { state: 'queued' | 'waiting' | 'signedIn' | 'signedOut' | 'failed' | 'cancelled'; url?: string | null; code?: string | null; message?: string | null } | null;
type Account = { id: number; label: string; active: boolean; login: Login };
type Status = {
    signedIn?: boolean; method?: string; billing?: string; email?: string | null; organizationName?: string | null;
    subscriptionType?: string | null; loginExpiresAt?: string | null; shared?: boolean | null; error?: string; message?: string;
};
type Props = { accounts: Account[]; available: boolean };

const pending = (login: Login) => login?.state === 'queued' || login?.state === 'waiting';
const methods = [{ value: 'subscription', label: 'Claude subscription (claude.ai)' }, { value: 'console', label: 'Console (API usage billing)' }];
const billingLabel: Record<string, string> = { subscription: 'Subscription', apiUsage: 'API usage', none: 'No billing', unknown: 'Billing unknown' };
const loginLabel: Record<string, string> = {
    queued: 'Waiting for the overseer worker to start the sign-in…',
    waiting: 'Finish signing in in the tab that opened. This page updates by itself.',
    signedIn: 'Signed in.',
    signedOut: 'The CLI finished, but reports no sign-in.',
    failed: 'The sign-in did not finish.',
    cancelled: 'The sign-in was cancelled.',
};

async function send(path: string, body: Record<string, unknown> = {}, method: 'POST' | 'GET' = 'POST') {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        ...(method === 'POST' ? { body: JSON.stringify(body) } : {}),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = json.errors ? Object.values(json.errors as Record<string, string[]>).flat().join(' ') : null;
        throw new Error(errors || json.error || json.message || `The request failed (${response.status}).`);
    }
    return json;
}

export default function Accounts({ accounts: initial, available }: Props) {
    const [accounts, setAccounts] = useState<Account[]>(initial);
    const [statuses, setStatuses] = useState<{ default?: Status; accounts: Record<string, Status> }>({ accounts: {} });
    const [checking, setChecking] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [label, setLabel] = useState('');
    const [method, setMethod] = useState('subscription');
    const [shareOnAdd, setShareOnAdd] = useState(false);
    const tabs = useRef<Record<number, Window | null>>({});
    const polling = useRef<Set<number>>(new Set());

    const setLogin = (id: number, login: Login) => setAccounts(current => current.map(account => account.id === id ? { ...account, login } : account));

    const check = useCallback(async () => {
        setChecking(true);
        try {
            const json = await send('/lab/accounts/check');
            setStatuses({ default: json.default, accounts: json.accounts });
            setAccounts(json.list);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setChecking(false);
        }
    }, []);

    useEffect(() => { void check(); }, [check]);

    const showUrl = (id: number, url: string | null | undefined) => {
        const tab = tabs.current[id];
        if (!url || !tab || tab.closed) return;
        tab.location.href = url;
        tabs.current[id] = null;
    };

    const poll = async (id: number) => {
        if (polling.current.has(id)) return;
        polling.current.add(id);
        try {
            for (let i = 0; i < 900; i++) {
                await new Promise(resolve => setTimeout(resolve, 2000));
                const { login } = await send(`/lab/accounts/${id}/login`, {}, 'GET');
                setLogin(id, login);
                showUrl(id, login?.url);
                if (!pending(login)) break;
            }
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            polling.current.delete(id);
            const tab = tabs.current[id];
            try {
                if (tab && !tab.closed && tab.location.href === 'about:blank') tab.close();
            } catch {
                // A tab that moved on to another origin is the person's now.
            }
            tabs.current[id] = null;
            void check();
        }
    };

    // Open the tab on the click itself, so the browser treats it as the person's; it gets the sign-in URL when the
    // worker has it. If the browser blocked it, the card shows the same URL as a button instead.
    const openTab = (id: number) => {
        const tab = window.open('', '_blank');
        if (tab) tab.opener = null;
        tabs.current[id] = tab;
    };

    const started = (json: { account: Account; login: Login }) => {
        setAccounts(current => [...current.filter(account => account.id !== json.account.id), { ...json.account, login: json.login }].sort((a, b) => a.label.localeCompare(b.label)));
        showUrl(json.account.id, json.login?.url);
        if (pending(json.login)) void poll(json.account.id);
        else void check();
    };

    const act = async (key: string, operation: () => Promise<void>) => {
        setBusy(key);
        setError(null);
        try {
            await operation();
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(null);
        }
    };

    const add = () => act('add', async () => {
        const tab = window.open('', '_blank');
        if (tab) tab.opener = null;
        try {
            const json = await send('/lab/accounts', { label: label.trim(), method, shared: shareOnAdd });
            tabs.current[json.account.id] = tab;
            setLabel('');
            started(json);
        } catch (failure) {
            tab?.close();
            throw failure;
        }
    });

    const signIn = (account: Account) => act(`sign-in-${account.id}`, async () => {
        openTab(account.id);
        try {
            started(await send(`/lab/accounts/${account.id}/login`, { method }));
        } catch (failure) {
            tabs.current[account.id]?.close();
            tabs.current[account.id] = null;
            throw failure;
        }
    });

    const cancel = (account: Account) => act(`cancel-${account.id}`, async () => {
        const { login } = await send(`/lab/accounts/${account.id}/login/cancel`);
        setLogin(account.id, login);
    });

    const signOut = (account: Account) => act(`sign-out-${account.id}`, async () => {
        await send(`/lab/accounts/${account.id}/logout`);
        await check();
    });

    const activate = (id: number | null) => act(`active-${id ?? 'default'}`, async () => {
        const json = await send('/lab/accounts/active', { account: id });
        setAccounts(json.list);
    });

    const share = (account: Account, shared: boolean) => act(`shared-${account.id}`, async () => {
        await send(`/lab/accounts/${account.id}/shared`, { shared });
        await check();
    });

    const defaultActive = !accounts.some(account => account.active);

    const statusBadges = (status: Status | undefined) => {
        if (!status) return <Badge color="zinc" variant="soft" size="sm">{checking ? 'Checking…' : 'Not checked'}</Badge>;
        if (status.error) return <Badge color="red" variant="soft" size="sm">Check failed</Badge>;
        return <>
            <Badge color={status.signedIn ? 'green' : 'amber'} variant="soft" size="sm" dot>{status.signedIn ? 'Signed in' : 'Signed out'}</Badge>
            {status.signedIn && <Badge color="zinc" variant="outline" size="sm">{status.method}</Badge>}
            {status.signedIn && <Badge color={status.billing === 'apiUsage' ? 'amber' : 'zinc'} variant="outline" size="sm">{billingLabel[status.billing ?? 'unknown'] ?? status.billing}</Badge>}
        </>;
    };

    const details = (status: Status | undefined) => status && (status.error
        ? <Text size="sm" color="danger">{status.message}</Text>
        : <Text size="sm" color="muted">{[status.email, status.organizationName, status.subscriptionType, status.loginExpiresAt ? `sign-in expires ${status.loginExpiresAt}` : null].filter(Boolean).join(' · ') || 'The CLI reported no account details.'}</Text>);

    return <LabShell title="Claude accounts" current="/lab/accounts" eyebrow="prism-acp">
        <Card variant="flat" className="mb-4">
            <Card.Header
                heading="Claude accounts"
                headingLevel="h1"
                description="The account the Lab's Claude launches use. Signing in happens on Anthropic's own page, in a new tab; the Claude CLI keeps its sign-in in its own folder. The Lab stores only a label, that folder and which account is active."
                actions={<Button type="button" data-accounts-action="check" variant="ghost" onClick={() => void check()} loading={checking} disabled={checking}>Check</Button>}
            />
        </Card>

        {!available && <Callout color="amber" className="mb-4">The prism-acp TypeScript package is not built where the Lab looks for it (LAB_PRISM_ACP_MODULE, by default ../prism-acp-ts/dist/index.js). Check and sign-in will fail until it is.</Callout>}
        {error && <Callout color="red" className="mb-4" role="alert" dismissible onDismiss={() => setError(null)}>{error}</Callout>}

        <Grid cols={2} gap="md">
            <Card data-account="default">
                <Card.Header heading="Default account" description="The Claude CLI's own sign-in. Sign it in or out with the CLI itself; this page never changes it." actions={defaultActive ? <Badge color="cyan" size="sm">Active</Badge> : null} />
                <Card.Body>
                    {statusBadges(statuses.default)} {details(statuses.default)}
                </Card.Body>
                <Card.Footer actions={<Button type="button" size="sm" data-accounts-action="activate-default" disabled={defaultActive || busy !== null} loading={busy === 'active-default'} onClick={() => void activate(null)}>Make active</Button>} />
            </Card>

            {accounts.map(account => {
                const status = statuses.accounts[String(account.id)];
                const login = account.login;
                const waiting = pending(login);
                return <Card key={account.id} data-account={account.label}>
                    <Card.Header heading={account.label} description={`storage/app/claude-accounts/${account.label}`} actions={account.active ? <Badge color="cyan" size="sm">Active</Badge> : null} />
                    <Card.Body>
                        {statusBadges(status)} {details(status)}
                        <Switch
                            className="mt-3"
                            label="Shared sessions (continue the default account's threads here)"
                            checked={status?.shared === true}
                            disabled={busy !== null || waiting || status === undefined}
                            onCheckedChange={checked => void share(account, checked)}
                        />
                        {login && <Callout color={login.state === 'failed' ? 'red' : login.state === 'signedIn' ? 'green' : 'zinc'} className="mt-3" role="status">
                            {login.message ?? loginLabel[login.state]}
                            {login.state === 'waiting' && login.url && <> <Button href={login.url} target="_blank" rel="noopener noreferrer" variant="ghost" size="sm">Open the sign-in page</Button></>}
                        </Callout>}
                    </Card.Body>
                    <Card.Footer actions={<>
                        <Button type="button" size="sm" data-accounts-action={`activate-${account.label}`} disabled={account.active || busy !== null} loading={busy === `active-${account.id}`} onClick={() => void activate(account.id)}>Make active</Button>
                        {waiting
                            ? <Button type="button" size="sm" variant="ghost" data-accounts-action={`cancel-${account.label}`} disabled={busy !== null} onClick={() => void cancel(account)}>Cancel sign-in</Button>
                            : status?.signedIn
                                ? <Button type="button" size="sm" variant="ghost" data-accounts-action={`sign-out-${account.label}`} disabled={busy !== null} loading={busy === `sign-out-${account.id}`} onClick={() => void signOut(account)}>Sign out</Button>
                                : <Button type="button" size="sm" variant="ghost" data-accounts-action={`sign-in-${account.label}`} disabled={busy !== null || !available} loading={busy === `sign-in-${account.id}`} onClick={() => void signIn(account)}>Sign in</Button>}
                    </>} />
                </Card>;
            })}

            <Card data-account="add">
                <Card.Header heading="Add account" description="Makes a folder for it under the Lab's storage and opens Claude's sign-in in a new tab." />
                <Card.Body>
                    <Input id="account-label" label="Label" placeholder="work" value={label} onValueChange={setLabel} maxLength={32} />
                    <Select id="account-method" label="Sign in with" list={methods} value={method} onValueChange={setMethod} className="mt-3" />
                    <Switch className="mt-3" label="Shared sessions" checked={shareOnAdd} onCheckedChange={setShareOnAdd} />
                </Card.Body>
                <Card.Footer actions={<Button type="button" data-accounts-action="add" disabled={label.trim() === '' || busy !== null || !available} loading={busy === 'add'} onClick={() => void add()}>Add account</Button>} />
            </Card>
        </Grid>
    </LabShell>;
}
