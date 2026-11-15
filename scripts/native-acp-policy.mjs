// Optional Windows native control: node scripts/native-acp-policy.mjs <php.exe> <codex.exe>
// All responses come from loopback fixtures. No user home, credentials or paid model calls.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const [php, binary] = process.argv.slice(2);
assert(php && binary, 'Pass PHP and Codex executable paths');
assert.equal(process.platform, 'win32', 'Native PHP containment currently supports Windows');
const nativeVersion = execFileSync(binary, ['--version'], { encoding: 'utf8', windowsHide: true }).trim();
assert.match(nativeVersion, /\b0\.163\.0\b/, 'Review the native control when changing the measured CLI version');
const root = await mkdtemp(join(tmpdir(), 'prism-lab-policy-'));
let state;
let shellCall;
const mcpCall = { type: 'function_call', id: 'fc-mcp', call_id: 'call-mcp', namespace: 'mcp__fixture', name: 'sentinel', arguments: '{}' };
function flatten(tools, namespace) {
    return tools.flatMap(tool => Array.isArray(tool.tools) ? flatten(tool.tools, tool.name) : [{ namespace, name: tool.name }]);
}
const http = createServer((request, response) => { void (async () => {
    let text = ''; for await (const bytes of request) { text += bytes; assert(text.length < 5_000_000); }
    assert.equal(request.method, 'POST');
    const body = JSON.parse(text);
    if (request.url === '/model/responses') {
        state.bodies.push(body);
        const n = state.bodies.length;
        assert(n <= 3, 'Bounded model fixture requests');
        const id = 'fixture-' + n;
        let item;
        if (n === 1) {
            if (state.kind === 'shell' && !state.guarded) {
                const tool = flatten(body.tools ?? []).find(t => /^(exec_command|shell_command|shell)$/.test(t.name));
                assert(tool, 'Positive control offers a real shell tool');
                const args = tool.name === 'exec_command' ? { cmd: 'Write-Output PRISM_LAB_SHELL_CONTROL', yield_time_ms: 1000, max_output_tokens: 1000 }
                    : tool.name === 'shell_command' ? { command: 'Write-Output PRISM_LAB_SHELL_CONTROL', timeout_ms: 10000 }
                        : { command: ['powershell.exe', '-NoProfile', '-Command', 'Write-Output PRISM_LAB_SHELL_CONTROL'], timeout_ms: 10000 };
                shellCall = { type: 'function_call', id: 'fc-shell', call_id: 'call-shell', ...tool, arguments: JSON.stringify(args) };
            }
            item = state.kind === 'mcp' ? mcpCall : shellCall;
            assert(item, 'Guarded case reuses the exact baseline invocation');
        } else {
            item = { type: 'message', id: id + '-message', role: 'assistant', content: [{ type: 'output_text', text: 'Local policy fixture complete' }] };
        }
        const events = [{ type: 'response.created', response: { id } }, { type: 'response.output_item.done', item },
            { type: 'response.completed', response: { id, usage: { input_tokens: 1, output_tokens: 1, total_tokens: 2 } } }];
        response.writeHead(200, { 'Content-Type': 'text/event-stream' });
        response.end(events.map(e => `event: ${e.type}\ndata: ${JSON.stringify(e)}\n\n`).join(''));
        return;
    }
    assert.equal(request.url, '/mcp');
    state.mcp.push(body.method);
    if (body.id === undefined) { response.writeHead(202); response.end(); return; }
    const result = body.method === 'initialize' ? { protocolVersion: body.params.protocolVersion, capabilities: { tools: {} }, serverInfo: { name: 'fixture', version: '1' } }
        : body.method === 'tools/list' ? { tools: [{ name: 'sentinel', description: 'Owned harmless positive control', inputSchema: { type: 'object' },
            annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false } }] }
            : { content: [{ type: 'text', text: 'PRISM_LAB_MCP_CONTROL' }] };
    response.writeHead(200, { 'Content-Type': 'application/json' }); response.end(JSON.stringify({ jsonrpc: '2.0', id: body.id, result }));
})().catch(error => { state.errors.push(error.message); response.destroy(); }); });
await new Promise(resolve => http.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${http.address().port}`;
async function run(kind, guarded, home, project, resumeSessionId) {
    state = { kind, guarded, bodies: [], mcp: [], errors: [] };
    const options = join(root, 'options.json');
    await writeFile(options, JSON.stringify({ kind, guarded, home, cwd: project, binary, resumeSessionId }));
    const child = spawn(php, [fileURLToPath(new URL('./native-acp-policy-host.php', import.meta.url)), options], { windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '', stderr = '';
    child.stdout.on('data', bytes => { stdout += bytes; }); child.stderr.on('data', bytes => { stderr += bytes; });
    const timer = setTimeout(() => child.kill(), 45_000);
    let code; try { code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); }); } finally { clearTimeout(timer); }
    assert.equal(code, 0, `Native PHP fixture failed: ${stderr}`);
    const events = stdout.trim().split('\n').map(line => JSON.parse(line));
    const result = events.find(event => event.outcome), stop = events.find(event => event.stop)?.stop;
    assert.deepEqual(result.problems, []); assert.equal(result.outcome.isError, false); assert.equal(result.outcome.stopReason, 'end_turn');
    assert.equal(stop.verified, true); assert.equal(stop.empty, true); assert.equal(stop.survivors, 0);
    assert.deepEqual(state.errors, []); assert.equal(state.bodies.length, 2); assert.equal(result.approvals, 0);
    const output = state.bodies.flatMap(body => (body.input ?? []).filter(item => item.type === 'function_call_output')).map(item => JSON.stringify(item.output)).join('\n');
    if (kind === 'mcp') {
        assert.equal(state.mcp.includes('tools/call'), !guarded, 'Same hidden MCP invocation must be denied under the Lab policy');
        if (guarded) { assert.equal(state.mcp.length, 0); assert.match(output, /unsupported|unknown|not found|unavailable/i); }
        else { assert.match(output, /PRISM_LAB_MCP_CONTROL/); }
    } else {
        if (guarded) { assert(!output.includes('PRISM_LAB_SHELL_CONTROL')); assert.match(output, /unsupported|unknown|not found|unavailable/i); }
        else { assert.match(output, /PRISM_LAB_SHELL_CONTROL/); }
    }
    console.log(JSON.stringify({ passed: true, nativeVersion, kind, guarded, resumed: !!resumeSessionId, modelCalls: state.bodies.length, mcpCalls: state.mcp.length, stop }));
    return result.sessionId;
}
try {
    for (const kind of ['mcp', 'shell']) {
        const home = join(root, kind + '-home'), project = join(root, kind + '-project');
        await mkdir(home); await mkdir(join(project, '.git'), { recursive: true });
        await writeFile(join(home, 'config.toml'), `model="fixture"\nmodel_provider="fixture"\n[model_providers.fixture]\nname="Fixture"\nbase_url="${origin}/model"\nwire_api="responses"\nrequires_openai_auth=false\nsupports_websockets=false\n[projects.${JSON.stringify(project)}]\ntrust_level="trusted"\n[features]\nunified_exec=false\n${kind === 'mcp' ? `[mcp_servers.fixture]\nurl="${origin}/mcp"\ndefault_tools_approval_mode="auto"\n` : ''}`);
        await run(kind, false, home, project);
        const session = await run(kind, true, home, project);
        await run(kind, true, home, project, session);
    }
} finally {
    http.closeAllConnections(); await new Promise(resolve => http.close(resolve));
    assert(root.startsWith(join(tmpdir(), 'prism-lab-policy-')));
    await rm(root, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
