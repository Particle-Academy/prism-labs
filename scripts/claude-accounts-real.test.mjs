// The Lab against the REAL built @particle-academy/prism-acp (the TypeScript package), not the stand-in
// tests/fixtures/claude-accounts/fake-prism-acp.mjs that ClaudeAccountsTest uses. CI's ACP job checks out
// ../prism-acp-ts at `node scripts/dogfood-dependencies.mjs --npm-ref prism-acp-ts` (main, or its pin in
// dogfood-candidate-pins.json), policy-checks it, builds it (--build-npm) and runs this, so a prism-acp-ts candidate
// is exercised here before it is published.
//
// The only CLI ever run is tests/fixtures/claude-accounts/fake-claude-cli.mjs, through the package's own spawn, in
// account directories under a fresh temp dir. No real Claude CLI, account directory, ~/.claude or credential is
// touched: every call names its binary, and the child processes get a temp HOME.
//
// A missing module FAILS: a run that silently fell back to the stand-in would be a claim, not a check.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { after, test } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const modulePath = resolve(process.env.LAB_PRISM_ACP_MODULE || resolve(root, '..', 'prism-acp-ts', 'dist', 'index.js'));
const fakeCli = resolve(root, 'tests', 'fixtures', 'claude-accounts', 'fake-claude-cli.mjs');
const bridge = resolve(root, 'scripts', 'claude-accounts.mjs');

assert.ok(existsSync(modulePath), `the built prism-acp-ts module is missing at ${modulePath}; acp.yml checks it out (actions/checkout at the --npm-ref ref) before --build-npm`);
assert.ok(!modulePath.startsWith(resolve(root, 'tests')), 'this test must load the real package, not a fixture');
const acp = await import(pathToFileURL(modulePath).href);
assert.equal(typeof acp.claudeAccounts?.readRateLimits, 'function', 'the built package has no claudeAccounts.readRateLimits');

const base = mkdtempSync(join(tmpdir(), 'lab-acp-real-'));
const home = join(base, 'home');
mkdirSync(home);
after(() => rmSync(base, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 }));

const FAKE_KEY = 'sk-ant-lab-fixture-never-real';
const cli = { binary: process.execPath, binaryArgs: [fakeCli] };
const account = (caseName, name) => { const parent = join(base, caseName); mkdirSync(parent, { recursive: true }); return join(parent, name); };
const runs = (dir) => {
    const file = join(dirname(dir), 'fake-cli-runs.jsonl');
    return existsSync(file) ? readFileSync(file, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line)) : [];
};
const read = (dir, options = {}) => acp.claudeAccounts.readRateLimits(dir, {
    ...cli, cwd: base, maxAgeMs: 0, timeoutMs: 30_000,
    parentEnv: { ...process.env, HOME: home, USERPROFILE: home, ANTHROPIC_API_KEY: FAKE_KEY, CLAUDE_CONFIG_DIR: join(base, 'inherited') },
    ...options,
});

function assertNoTurnAndNoCredential(run) {
    assert.deepEqual(run.stdin.map(({ type, subtype }) => [type, subtype]), [['control_request', 'get_usage']], 'only the get_usage request may be written: no initialize, no user message, no turn');
    assert.equal(run.stdin[0].skip_behaviors, true);
    assert.ok(!run.env.includes('ANTHROPIC_API_KEY'), 'an outranking credential reached the CLI');
    assert.ok(run.argv.includes('--safe-mode') && run.argv.includes('--strict-mcp-config'));
}

test('a signed-in account reads its windows, including the model-scoped Fable cap', async () => {
    const dir = account('windows', 'signed');
    const reading = await read(dir);
    assert.equal(reading.state, 'reported', JSON.stringify(reading));
    assert.equal(reading.reasonCode, undefined);
    const byId = Object.fromEntries(reading.windows.map((window) => [window.id, window]));
    assert.deepEqual(Object.keys(byId).sort(), ['claude:five_hour', 'claude:model:fable', 'claude:seven_day']);
    assert.equal(byId['claude:five_hour'].usedPercent, 41);
    assert.equal(byId['claude:five_hour'].resetsAtMs, Date.parse('2026-12-01T03:00:00.000Z'));
    assert.equal(byId['claude:seven_day'].usedPercent, 34);
    assert.equal(byId['claude:seven_day'].scope, undefined);
    assert.deepEqual(byId['claude:model:fable'].scope, { model: 'Fable' });
    assert.equal(byId['claude:model:fable'].usedPercent, 100);
    assert.equal(byId['claude:model:fable'].limited, true);
    assert.equal(reading.limited, true, 'a cap at its allowance limits the account');
    // Nothing but the windows is carried: no plan, spend or session cost.
    assert.ok(!JSON.stringify(reading).includes('total_cost_usd') && !JSON.stringify(reading).includes('subscription_type'));
    const [run] = runs(dir);
    assertNoTurnAndNoCredential(run);
    assert.ok(run.env.includes('CLAUDE_CONFIG_DIR'));
});

test('a signed-out account reads as unsupported with no_plan_limits', async () => {
    const dir = account('signed-out', 'nobody');
    const reading = await read(dir);
    assert.equal(reading.state, 'unsupported', JSON.stringify(reading));
    assert.equal(reading.reasonCode, 'no_plan_limits');
    assert.deepEqual(reading.windows, []);
    assert.equal(reading.limited, false);
    assertNoTurnAndNoCredential(runs(dir)[0]);
});

test('a missing CLI reads as cli_unavailable and never rejects', async () => {
    const dir = account('missing-cli', 'signed');
    const reading = await read(dir, { binary: join(base, 'no-such-claude', process.platform === 'win32' ? 'claude.exe' : 'claude'), binaryArgs: [] });
    assert.equal(reading.state, 'unreadable', JSON.stringify(reading));
    assert.equal(reading.reasonCode, 'cli_unavailable');
    assert.deepEqual(reading.windows, []);
    assert.deepEqual(runs(dir), [], 'nothing ran');
});

test('a relative directory is refused before any CLI starts', async () => {
    const reading = await read('relative/account');
    assert.equal(reading.reasonCode, 'invalid_options');
});

// The Lab's existing paths, through scripts/claude-accounts.mjs exactly as NodeClaudeAccountBridge runs it, with the
// real module in LAB_PRISM_ACP_MODULE.
function lab(command, args) {
    const output = execFileSync(process.execPath, [bridge, command, JSON.stringify(args)], {
        cwd: base, encoding: 'utf8', timeout: 60_000, windowsHide: true,
        env: { ...process.env, HOME: home, USERPROFILE: home, LAB_PRISM_ACP_MODULE: modulePath, ANTHROPIC_API_KEY: FAKE_KEY },
    });
    const events = output.trim().split('\n').filter(Boolean).map((line) => JSON.parse(line));
    assert.equal(events.length, 1, output);
    return events[0];
}

test('describe works through the Lab bridge and the real module', () => {
    const signed = account('describe', 'signed');
    assert.deepEqual(lab('describe', { configDir: signed, ...cli }), {
        type: 'result',
        value: {
            signedIn: true, method: 'subscription', billing: 'subscription', email: 'person@example.test', organizationName: 'Fixture Org',
            subscriptionType: 'max', configDirectoryMatches: true, loginExpiresAt: null,
        },
    });
    assert.ok(!runs(signed)[0].env.includes('ANTHROPIC_API_KEY'));
    const out = lab('describe', { configDir: account('describe', 'nobody'), ...cli });
    assert.equal(out.type, 'result');
    assert.equal(out.value.signedIn, false);
    assert.equal(out.value.method, 'none');
});

test('prepare and shared work through the Lab bridge and the real module', () => {
    const dir = account('prepare', 'fresh');
    assert.deepEqual(lab('prepare', { configDir: dir, sharedSessions: false }), { type: 'result', value: { sharedSessions: false, linked: null } });
    assert.ok(existsSync(dir), 'prepare creates the account directory');
    assert.deepEqual(lab('shared', { configDir: dir }), { type: 'result', value: { shared: null } });
    assert.deepEqual(lab('prepare', { configDir: 'relative' }), { type: 'error', code: 'configDirInvalid' });
});
