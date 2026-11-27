// A stand-in for the Claude CLI, for scripts/claude-accounts-real.test.mjs. It is run by the REAL built
// @particle-academy/prism-acp as `node fake-claude-cli.mjs <argv...>`, so the package's own spawn, environment
// stripping, framing and parsing are what is under test. No real CLI, account or credential is involved: the answers
// below are invented values in the shapes prism-acp-ts measured on claude-code 2.1.332.
//
// The account directory's last segment (CLAUDE_CONFIG_DIR, set by prism-acp) picks the behaviour:
//   signed     signed in, with windows including a model-scoped Fable cap
//   anything   signed out: `auth status` says not logged in, `get_usage` says plan limits do not apply
//
// Each run appends one JSON line (when it answers, or at exit) to <CLAUDE_CONFIG_DIR>/../fake-cli-runs.jsonl: its
// argv, the NAMES of its environment (never the values) and the type/subtype of every stdin line, so the test can prove
// what reached it.
import { appendFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';

const argv = process.argv.slice(2);
const dir = process.env.CLAUDE_CONFIG_DIR ?? '';
const signed = dir !== '' && basename(dir) === 'signed';
const lines = [];
let recorded = false;
const record = () => {
    if (dir === '' || recorded) return;
    recorded = true;
    appendFileSync(join(dirname(dir), 'fake-cli-runs.jsonl'), `${JSON.stringify({ argv, env: Object.keys(process.env).sort(), stdin: lines })}\n`);
};

if (argv.includes('auth') && argv.includes('status')) {
    record();
    process.stdout.write(JSON.stringify(signed
        ? { loggedIn: true, authMethod: 'claude.ai', apiProvider: 'firstParty', email: 'person@example.test', orgName: 'Fixture Org', subscriptionType: 'max', configDirectory: dir }
        : { loggedIn: false, authMethod: 'none', apiProvider: 'firstParty', configDirectory: dir }));
    process.exit(0);
}

const SIGNED_IN = {
    session: { total_cost_usd: 0, total_duration_ms: 500, model_usage: {} },
    subscription_type: 'max',
    rate_limits_available: true,
    rate_limits: {
        five_hour: { utilization: 41, resets_at: '2026-12-01T03:00:00.000Z' },
        seven_day: { utilization: 34, resets_at: '2026-12-01T08:00:00.000Z', limit_dollars: null },
        seven_day_opus: null,
        model_scoped: [{ display_name: 'Fable', utilization: 100, resets_at: '2026-12-02T09:30:00.000Z' }],
    },
    behaviors: null,
};
const SIGNED_OUT = { session: { total_cost_usd: 0, model_usage: {} }, rate_limits_available: false, rate_limits: null, behaviors: null };

// Stream-json mode: answer a get_usage control request, then wait for stdin to close, as the CLI does.
let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
    buffer += chunk;
    let at;
    while ((at = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, at);
        buffer = buffer.slice(at + 1);
        let message;
        try { message = JSON.parse(line); } catch { lines.push({ type: 'unparsed' }); continue; }
        lines.push({ type: message?.type ?? null, subtype: message?.request?.subtype ?? null, skip_behaviors: message?.request?.skip_behaviors ?? null });
        if (message?.type === 'control_request' && message.request?.subtype === 'get_usage') {
            process.stdout.write(`${JSON.stringify({ type: 'control_response', response: { subtype: 'success', request_id: message.request_id, response: signed ? SIGNED_IN : SIGNED_OUT } })}\n`);
            // prism-acp stops the CLI as soon as it has the answer, so the run is recorded now, not at exit.
            record();
        }
    }
});
process.stdin.on('end', () => { record(); process.exit(0); });
