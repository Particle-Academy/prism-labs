// Lab consumer scenario for @particle-academy/prism-harness (TypeScript port).
//
// Runs from a throwaway consumer project that installed the packed tarball, so the package is resolved by name from
// that project's node_modules, exactly as a consumer resolves it. Public exports only; a fake model client; the
// network is blocked for the whole process and the scenario fails if anything reached for it.
//
// For one gated call, it checks four things across every case below:
//   1. the call runs at most once;
//   2. every gated call in the thread (the parked one, and each call a recorded copy holds) has exactly one outcome;
//   3. the call never runs with arguments other than those in the request the person approved;
//   4. one approval never runs two calls.
import dc from 'node:diagnostics_channel';
import net from 'node:net';
import { existsSync, lstatSync, readFileSync, realpathSync } from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

// --- Network block, installed before the package is loaded ---------------------------------------------------
const network = { fetch: 0, connect: 0, sockets: 0 };
globalThis.fetch = () => {
    network.fetch += 1;
    throw new Error('The network is blocked in this scenario.');
};
net.Socket.prototype.connect = function blockedConnect() {
    network.connect += 1;
    throw new Error('The network is blocked in this scenario.');
};
dc.subscribe('net.client.socket', () => { network.sockets += 1; });

// Prove the block is live before trusting a zero later.
try {
    net.connect(9, '127.0.0.1');
    throw new Error('A socket connect was not blocked.');
} catch (error) {
    if (network.connect !== 1) throw error;
}
try {
    await globalThis.fetch('http://127.0.0.1:9/');
} catch {
    // expected
}
if (network.fetch !== 1) throw new Error('fetch was not blocked.');
const baseline = { ...network };

// --- The package, resolved by name from the consumer project, from an installed tarball -----------------------
const PACKAGE = '@particle-academy/prism-harness';
const here = dirname(fileURLToPath(import.meta.url));
const installed = join(here, 'node_modules', ...PACKAGE.split('/'));
const entry = fileURLToPath(import.meta.resolve(PACKAGE));
if (!entry.startsWith(installed + sep)) throw new Error(`${PACKAGE} resolved outside the consumer project: ${entry}`);
if (lstatSync(installed).isSymbolicLink() || realpathSync(installed) !== installed) throw new Error(`${PACKAGE} is a link, not an installed artifact.`);
const lockFile = join(here, 'package-lock.json');
if (!existsSync(lockFile)) throw new Error('The consumer project has no package-lock.json.');
const resolved = JSON.parse(readFileSync(lockFile, 'utf8')).packages?.[`node_modules/${PACKAGE}`]?.resolved ?? '';
if (!/^file:.+\.tgz$/.test(resolved)) throw new Error(`${PACKAGE} was not installed from a packed tarball (resolved: ${resolved || 'none'}).`);
const version = JSON.parse(readFileSync(join(installed, 'package.json'), 'utf8')).version;
console.log(`Installed ${PACKAGE} ${version} from ${resolved}`);
if (process.env.HARNESS_SHA) console.log(`Candidate commit: ${process.env.HARNESS_SHA}`);

const { AgentRuntime, MemorySessionStore, ModeRegistry, PrismHarness, ToolRegistry, recordApproval } = await import(PACKAGE);
for (const [name, value] of Object.entries({ AgentRuntime, MemorySessionStore, ModeRegistry, PrismHarness, ToolRegistry, recordApproval })) {
    if (typeof value !== 'function') throw new Error(`${PACKAGE} does not export ${name}.`);
}

// --- Rig ------------------------------------------------------------------------------------------------------
class DurableMemory extends MemorySessionStore {
    durability() { return 'durable'; }
}

const CALL = Object.freeze({ id: 'c1', name: 'echo', arguments: Object.freeze({ n: 1 }) });
let sessionCounter = 0;

function rig({ maxSteps = 1, failAfterResolution = false } = {}) {
    const harness = new PrismHarness({
        drivers: { memory: () => new MemorySessionStore(), durable: () => new DurableMemory() },
        stores: { ephemeral: 'memory', durable: 'durable' },
    });
    sessionCounter += 1;
    const r = { session: harness.for({ type: 'User', id: 1 }).session(`lab-${sessionCounter}`), ran: [], requests: 0 };
    const tools = new ToolRegistry()
        .register({ name: 'echo', handle: (args) => { r.ran.push(JSON.parse(JSON.stringify(args ?? null))); return `ran:${JSON.stringify(args)}`; } })
        .register({ name: 'free', handle: () => 'free' });
    const modes = new ModeRegistry({
        default: 'guarded',
        modes: { guarded: { system_prompt: 'Careful.', tools: ['echo', 'free'], max_steps: maxSteps, requires_approval: ['echo'] } },
    });
    r.agent = new AgentRuntime({
        modes,
        tools,
        client: async () => {
            r.requests += 1;
            if (r.requests === 1) return { text: '', finishReason: 'tool_calls', toolCalls: [JSON.parse(JSON.stringify(CALL))] };
            if (failAfterResolution) throw new Error('fake model failure');
            return { text: 'done', finishReason: 'stop' };
        },
    });
    return r;
}

async function withTimeout(promise) {
    let timer;
    try {
        return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('timed out')), 5000); })]);
    } finally {
        clearTimeout(timer);
    }
}

async function rows(session) {
    return (await session.thread().messages()).map((entry) => entry.message);
}

/**
 * Every call with this id the thread holds, and every outcome recorded for one, each as its canonical arguments.
 * A recorded copy of a step is a step of its own, so its calls are calls of their own: each call instance must have
 * exactly one outcome, which is checked as equality of the two multisets.
 */
async function callsAndOutcomes(session, callId) {
    const calls = [];
    const outcomes = [];
    for (const row of await rows(session)) {
        if (row?.type === 'assistant' && Array.isArray(row.tool_calls)) {
            for (const call of row.tool_calls) {
                if (call !== null && typeof call === 'object' && !Array.isArray(call) && call.id === callId) calls.push(canonical(call.arguments));
            }
        }
        if (row?.type === 'tool_result' && Array.isArray(row.tool_results)) {
            for (const entry of row.tool_results) if (entry?.tool_call_id === callId) outcomes.push(canonical(entry.args));
        }
    }
    return { calls: calls.sort(), outcomes: outcomes.sort() };
}

function canonical(value) {
    let parsed = value;
    if (typeof value === 'string') {
        try { parsed = JSON.parse(value); } catch { return JSON.stringify(value); }
    }
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return JSON.stringify(parsed ?? null);
    return JSON.stringify(Object.fromEntries(Object.keys(parsed).sort().map((key) => [key, parsed[key]])));
}

async function resumeThrice(r) {
    const errors = [];
    for (let i = 0; i < 3; i += 1) {
        await withTimeout(r.agent.send(r.session, '')).catch((error) => { errors.push(String(error?.message ?? error).slice(0, 120)); });
    }
    return errors;
}

// Copy-of-step builders. Each receives the parked call and returns the copy's tool_calls.
const changed = (call) => ({ ...JSON.parse(JSON.stringify(call)), arguments: typeof call.arguments === 'string' ? '{"n":2}' : { n: 2 } });
const original = (call) => JSON.parse(JSON.stringify(call));
const SHAPES = {
    'string': () => ['x'],
    'json-string': () => [JSON.stringify(CALL)],
    'number': () => [1],
    'null': () => [null],
    'array-of-strings': () => [['x', 'y']],
    'mixed': (call) => ['x', original(call), null, 1],
    'empty': () => [],
    'changed-args': (call) => [changed(call)],
    'string-then-changed': (call) => ['x', changed(call)],
    'null-then-changed': (call) => [null, changed(call)],
    'changed-then-mixed': (call) => [changed(call), 'x', null],
    'original-and-changed': (call) => [original(call), changed(call)],
    'exact': (call) => [original(call)],
};

/**
 * One case. `decision` is true (approve) or false (deny). `copy` is a SHAPES key or null. `order` is 'A' (decide,
 * then record the copy) or 'B' (record the copy, then decide). `keepRequest` keeps the approval request on the copy.
 */
async function runCase({ decision, copy = null, keepRequest = true, order = 'A', maxSteps = 1, failAfterResolution = false }) {
    const r = rig({ maxSteps, failAfterResolution });
    const first = await withTimeout(r.agent.send(r.session, 'go'));
    const pending = first.pendingApprovals ?? [];
    if (first.finishReason !== 'awaiting_approval' || pending.length !== 1) {
        return { problems: [`did not park on the gated call (finishReason ${first.finishReason}, ${pending.length} pending)`] };
    }
    const request = pending[0];
    const approvedArguments = JSON.parse(JSON.stringify(request.arguments));
    let decided = false;
    const decide = async () => {
        try {
            await recordApproval(r.session, request.id, decision);
            decided = true;
        } catch {
            decided = false;
        }
    };
    if (order === 'A') await decide();
    if (copy !== null) {
        const parked = [...await rows(r.session)].reverse()
            .find((row) => row?.type === 'assistant' && Array.isArray(row.tool_calls) && row.tool_calls.length > 0);
        const step = JSON.parse(JSON.stringify(parked));
        step.tool_calls = SHAPES[copy](parked.tool_calls[0]);
        if (!keepRequest) step.tool_approval_requests = [];
        await r.session.thread().record([step]);
    }
    if (order === 'B') await decide();
    const errors = await resumeThrice(r);
    const { calls, outcomes } = await callsAndOutcomes(r.session, CALL.id);

    const problems = [];
    if (r.ran.length > 1) problems.push(`the call ran ${r.ran.length} times`);
    if (!calls.includes(canonical(approvedArguments))) problems.push('the gated call is missing from the thread');
    if (JSON.stringify(calls) !== JSON.stringify(outcomes)) problems.push(`calls ${JSON.stringify(calls)} do not each have exactly one outcome ${JSON.stringify(outcomes)}`);
    if (!(decision && decided) && r.ran.length > 0) problems.push('the call ran without an approval');
    // Without a copy the person's decision must land and be honoured: an approved call runs exactly once, a denied one
    // never. Otherwise a harness that refused every decision, or ran nothing, would pass.
    if (copy === null && !decided) problems.push('the decision was refused');
    if (copy === null && decision && r.ran.length !== 1) problems.push(`the approved call ran ${r.ran.length} times, expected once`);
    for (const args of r.ran) {
        if (canonical(args) !== canonical(approvedArguments)) problems.push(`the call ran with arguments the person did not approve: ${JSON.stringify(args)}`);
    }
    return { problems, ran: r.ran.length, outcomes: outcomes.length, decided, errors };
}

const cases = [];
for (const decision of [true, false]) {
    cases.push({ label: `${decision ? 'approve' : 'deny'}, resume x3`, decision });
    cases.push({ label: `${decision ? 'approve' : 'deny'}, resume x3, max_steps 6, model fails after resolution`, decision, maxSteps: 6, failAfterResolution: true });
}
for (const copy of Object.keys(SHAPES)) {
    for (const keepRequest of [true, false]) {
        for (const order of ['A', 'B']) {
            for (const [maxSteps, failAfterResolution] of [[1, false], [6, true]]) {
                cases.push({
                    label: `copy-of-step ${copy}, ${keepRequest ? 'with' : 'without'} request, order ${order}, ${maxSteps === 1 ? 'max_steps 1' : 'max_steps 6, model fails after resolution'}`,
                    decision: true, copy, keepRequest, order, maxSteps, failAfterResolution,
                });
            }
        }
    }
}

let failed = 0;
for (const c of cases) {
    let result;
    try {
        result = await runCase(c);
    } catch (error) {
        result = { problems: [`threw: ${String(error?.message ?? error).slice(0, 160)}`] };
    }
    const ok = result.problems.length === 0;
    if (!ok) failed += 1;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${c.label}${ok ? '' : ` -- ${result.problems.join('; ')}`} (ran ${result.ran ?? '-'}, outcomes ${result.outcomes ?? '-'}, decided ${result.decided ?? '-'})`);
}

const used = Object.fromEntries(Object.entries(network).map(([key, value]) => [key, value - baseline[key]]));
const networkOk = Object.values(used).every((value) => value === 0);
if (!networkOk) console.log(`FAIL the network was reached for: ${JSON.stringify(used)}`);
console.log(`${cases.length - failed} of ${cases.length} cases passed; network use ${JSON.stringify(used)}.`);
if (failed || !networkOk) {
    console.log(`::error::${failed + (networkOk ? 0 : 1)} harness consumer check(s) failed.`);
    process.exitCode = 1;
}
