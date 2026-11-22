// The Lab's bridge to @particle-academy/prism-acp's `claudeAccounts` API (the TypeScript package).
//
// Run by App\Acp\Accounts\NodeClaudeAccountBridge as `node scripts/claude-accounts.mjs <command> <json>`. It writes
// one JSON object per line to stdout and nothing else:
//   {"type":"url","url":"https://claude.ai/..."}   login only: the sign-in URL, already checked by prism-acp
//   {"type":"result","value":{...}}                   the command finished
//   {"type":"error","code":"timeout"}                 it did not; a code, never the CLI's or the library's text
//
// It never reads, copies or prints a credential. The library runs the CLI's own `claude auth ...` commands, and the CLI
// keeps its sign-in in its own directory. The only path this script touches itself is `<configDir>/projects`, with
// lstat, to say whether that account shares the default account's threads.
//
// `login` reads stdin: a line saying `cancel`, or stdin closing (the Lab's worker went away), aborts the sign-in, and
// prism-acp then kills the CLI's whole process tree. So nothing is left running when the parent stops.
import { lstatSync, existsSync } from 'node:fs';
import { isAbsolute, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const CODES = new Set([
    'aborted', 'timeout', 'refusedUrl', 'noUrl', 'codeRequired', 'invalidCode', 'failed', 'refusedDirectory',
    'missingDirectory', 'realFolder', 'linkedElsewhere', 'occupied', 'unexpectedLink', 'storeMissing', 'storeInvalid',
    'configDirInvalid', 'linkFailed',
]);
const METHODS = new Set(['subscription', 'console', 'apiKey', 'none', 'other']);
const BILLING = new Set(['subscription', 'apiUsage', 'none', 'unknown']);

const emit = (event) => process.stdout.write(`${JSON.stringify(event)}\n`);
const codeOf = (error) => (typeof error?.code === 'string' && CODES.has(error.code) ? error.code : 'failed');

/** Named fields only, as prism-acp's own describe does: a field the CLI adds later never passes through here. */
function view(status) {
    const text = (value) => (typeof value === 'string' && value.length <= 320 ? value : null);
    return {
        signedIn: status?.signedIn === true,
        method: METHODS.has(status?.method) ? status.method : 'other',
        billing: BILLING.has(status?.billing) ? status.billing : 'unknown',
        email: text(status?.email),
        organizationName: text(status?.organizationName),
        subscriptionType: text(status?.subscriptionType),
        configDirectoryMatches: status?.configDirectoryMatches === true,
        loginExpiresAt: text(status?.loginExpiresAt),
    };
}

function directory(value, { allowNull = false } = {}) {
    if (value === null && allowNull) return null;
    if (typeof value !== 'string' || value === '' || value.includes('\0') || !isAbsolute(value)) throw Object.assign(new Error('bad directory'), { code: 'configDirInvalid' });
    return value;
}

function cli(args) {
    const binary = typeof args.binary === 'string' && args.binary !== '' ? args.binary : undefined;
    const binaryArgs = Array.isArray(args.binaryArgs) && args.binaryArgs.every((arg) => typeof arg === 'string') ? args.binaryArgs : undefined;
    return { ...(binary ? { binary } : {}), ...(binaryArgs ? { binaryArgs } : {}) };
}

async function login(acp, args) {
    if (typeof acp.claudeAccounts.login !== 'function' || typeof acp.claudeSignInUrl !== 'function') return emit({ type: 'error', code: 'unsupported' });
    const configDir = directory(args.configDir);
    const method = args.method === 'console' ? 'console' : 'subscription';
    const timeoutMs = Number.isInteger(args.timeoutMs) && args.timeoutMs > 0 && args.timeoutMs <= 3_600_000 ? args.timeoutMs : 600_000;
    const controller = new AbortController();
    let refused = false;
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => { if (chunk.includes('cancel')) controller.abort(); });
    process.stdin.on('end', () => controller.abort());
    process.stdin.on('error', () => controller.abort());
    try {
        const status = await acp.claudeAccounts.login(configDir, {
            ...cli(args),
            method,
            timeoutMs,
            signal: controller.signal,
            onUrl: (url) => {
                // prism-acp has already checked it; checking again here costs nothing and keeps this script honest
                // if it is ever pointed at a build that does not.
                const checked = acp.claudeSignInUrl(url);
                if (checked === null) {
                    refused = true;
                    controller.abort();
                    return;
                }
                emit({ type: 'url', url: checked });
            },
        });
        emit({ type: 'result', value: view(status) });
    } catch (error) {
        emit({ type: 'error', code: refused ? 'refusedUrl' : codeOf(error) });
    } finally {
        process.stdin.destroy();
    }
}

function shared(args) {
    const configDir = directory(args.configDir);
    try {
        const entry = lstatSync(join(configDir, 'projects'));
        // A Windows junction reads as a symbolic link here, which is what prepare() makes.
        return { shared: entry.isSymbolicLink() };
    } catch (error) {
        if (error?.code === 'ENOENT') return { shared: null };
        throw Object.assign(new Error('unreadable'), { code: 'failed' });
    }
}

async function main() {
    const [command, raw] = process.argv.slice(2);
    let args;
    try {
        args = JSON.parse(raw ?? '{}');
        if (args === null || typeof args !== 'object' || Array.isArray(args)) throw new Error('not an object');
    } catch {
        return emit({ type: 'error', code: 'badRequest' });
    }
    const modulePath = process.env.LAB_PRISM_ACP_MODULE ?? '';
    if (!isAbsolute(modulePath) || !/\.m?js$/.test(modulePath) || !existsSync(modulePath)) return emit({ type: 'error', code: 'moduleUnavailable' });
    let acp;
    try {
        acp = await import(pathToFileURL(modulePath).href);
    } catch {
        return emit({ type: 'error', code: 'moduleUnavailable' });
    }
    if (typeof acp?.claudeAccounts?.describe !== 'function') return emit({ type: 'error', code: 'moduleUnavailable' });

    try {
        switch (command) {
            case 'describe':
                return emit({ type: 'result', value: view(await acp.claudeAccounts.describe(directory(args.configDir, { allowNull: true }), { ...cli(args), timeoutMs: 30_000 })) });
            case 'login':
                return await login(acp, args);
            case 'logout':
                if (typeof acp.claudeAccounts.logout !== 'function') return emit({ type: 'error', code: 'unsupported' });
                return emit({ type: 'result', value: view(await acp.claudeAccounts.logout(directory(args.configDir), cli(args))) });
            case 'prepare': {
                const prepared = acp.claudeAccounts.prepare(directory(args.configDir), { sharedSessions: args.sharedSessions === true });
                return emit({ type: 'result', value: { sharedSessions: prepared.sharedSessions === true, linked: typeof prepared.linked === 'string' ? prepared.linked : null } });
            }
            case 'shared':
                return emit({ type: 'result', value: shared(args) });
            default:
                return emit({ type: 'error', code: 'badRequest' });
        }
    } catch (error) {
        return emit({ type: 'error', code: codeOf(error) });
    }
}

await main();
