import assert from 'node:assert/strict';
import fs from 'node:fs';
import { MicroMcpServer } from '@particle-academy/agent-integrations/mcp';
import { registerFormBridge } from '@particle-academy/agent-integrations/bridges/forms';

// The Lab's single-form adapter, without a relay, credentials, DOM or model.
// Run in both dependency lanes so a new first-party release must still honor
// the host's writes, refusals, confirmation and disposal.
const checks = [];
const turn = () => new Promise(resolve => setImmediate(resolve));

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

async function settled(promise) {
    let timer;
    try {
        return await Promise.race([promise, new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('Disposed form call did not settle')), 1500);
        })]);
    } finally {
        clearTimeout(timer);
    }
}

function mount(overrides = {}) {
    const values = { title: 'Owned fixture', notes: '' };
    const server = new MicroMcpServer({ info: { name: 'lab-form-control', version: '1.0.0' } });
    const adapter = {
        id: 'lab-form', title: 'Offline form',
        getFields: () => [{ name: 'title', type: 'text', required: true }, { name: 'notes', type: 'textarea' }],
        getValue: name => values[name], getValues: () => values,
        setValue: (name, value) => { values[name] = value; },
        setValues: next => { Object.assign(values, next); },
        submit: async () => ({ ok: true, values: { ...values } }),
        confirm: () => false,
        ...overrides,
    };
    const options = { adapter, pendingMode: true, agent: { id: 'lab-fixture' } };
    return { values, server, adapter, options, bridge: registerFormBridge(server, options) };
}

{
    const form = mount();
    const names = new Set(form.server.listTools().map(tool => tool.name));
    for (const name of ['form_describe', 'form_get_value', 'form_get_values', 'form_set_value', 'form_set_values', 'form_focus', 'form_submit']) {
        assert(names.has(name), `Existing form tool missing: ${name}`);
    }
    // New capabilities such as form_list may be added. Existing one-form calls
    // must still work without a form selector.
    assert.equal((await form.server.callTool('form_describe')).structuredContent.id, 'lab-form');
    checks.push('single-form call shape');

    assert(!(await form.server.callTool('form_set_value', { field: 'title', value: 'Changed' })).isError);
    assert.equal(form.values.title, 'Changed');
    assert((await form.server.callTool('form_set_values', { values: { unknown: 'refused' } })).isError);
    assert.equal(form.values.unknown, undefined);
    checks.push('known writes and unknown-field refusal');

    let allow = false, confirms = 0, submits = 0;
    form.adapter.confirm = () => { confirms++; return allow; };
    form.adapter.submit = async () => { submits++; return { ok: true }; };
    assert((await form.server.callTool('form_submit')).isError);
    assert.equal(submits, 0);
    allow = true;
    assert(!(await form.server.callTool('form_submit')).isError);
    assert.equal(submits, 1);
    assert.equal(confirms, 2);
    checks.push('human decline and approval');

    const replacement = registerFormBridge(form.server, form.options);
    form.bridge.dispose();
    assert.equal((await form.server.callTool('form_describe')).structuredContent.id, 'lab-form');
    checks.push('replacement survives old disposer');
    assert.throws(() => form.server.registerTool(
        { name: 'form_describe', inputSchema: { type: 'object' } },
        async () => ({ content: [] }), { owner: 'unrelated' },
    ), /already registered/);
    checks.push('unrelated owner cannot take over');
    replacement.dispose();
    assert.equal(form.server.listTools().filter(tool => tool.name.startsWith('form_')).length, 0);
    await assert.rejects(form.server.callTool('form_describe'), /withdrawn/);
    checks.push('disposed form is unreachable');
}

{
    const commit = deferred();
    let accepted = false, completed = false;
    const form = mount({ setValue: async () => { await commit.promise; accepted = true; } });
    const call = form.server.callTool('form_set_value', { field: 'title', value: 'Pending' });
    call.then(() => { completed = true; });
    await turn();
    assert.equal(completed, false);
    assert.equal(accepted, false);
    commit.resolve();
    assert(!(await settled(call)).isError);
    assert.equal(accepted, true);
    form.bridge.dispose();
    checks.push('write waits for host acceptance');
}

{
    const form = mount({ setValue: async () => ({ ok: false, error: 'Stale fixture', code: 'stale' }) });
    const result = await form.server.callTool('form_set_value', { field: 'title', value: 'Refused' });
    assert.equal(result.isError, true);
    assert(result.content.some(item => item.type === 'text' && item.text.includes('Stale fixture')));
    assert.equal(form.values.title, 'Owned fixture');
    form.bridge.dispose();
    checks.push('host refusal is an error');
}

{
    const commit = deferred(), unhandled = [];
    const capture = reason => unhandled.push(reason);
    process.on('unhandledRejection', capture);
    try {
        const form = mount({ setValue: () => commit.promise });
        const call = form.server.callTool('form_set_value', { field: 'title', value: 'Pending' });
        await turn();
        form.bridge.dispose();
        const result = await settled(call);
        assert.equal(result.isError, true);
        assert(/unknown/i.test(JSON.stringify(result)));
        commit.reject(new Error('Synthetic late host rejection'));
        await turn();
        await turn();
        assert.equal(unhandled.length, 0);
        checks.push('disposal settles pending write and handles late rejection');
    } finally {
        process.removeListener('unhandledRejection', capture);
    }
}

{
    const confirmation = deferred();
    let submits = 0;
    const form = mount({ confirm: () => confirmation.promise, submit: async () => { submits++; return { ok: true }; } });
    const call = form.server.callTool('form_submit');
    await turn();
    form.bridge.dispose();
    assert.equal((await settled(call)).isError, true);
    confirmation.resolve(true);
    await turn();
    assert.equal(submits, 0);
    checks.push('disposed pending confirmation never submits');
}

{
    const calls = [];
    const form = mount({
        getFields: () => ['title', 'notes', 'third'].map(name => ({ name, type: 'text' })),
        setValues: undefined,
        setValue: async name => { calls.push(name); return name === 'notes' ? { ok: false, error: 'Refused' } : { ok: true }; },
    });
    const result = await form.server.callTool('form_set_values', { values: { title: 'Accepted', notes: 'Refused', third: 'Untouched' } });
    assert.equal(result.isError, true);
    assert.deepEqual(calls, ['title', 'notes']);
    assert.deepEqual(result.structuredContent.committed, ['title']);
    assert.equal(result.structuredContent.failed, 'notes');
    assert.deepEqual(result.structuredContent.notAttempted, ['third']);
    form.bridge.dispose();
    checks.push('bulk fallback stops and reports partial acceptance');
}

const { version } = JSON.parse(fs.readFileSync(new URL('../node_modules/@particle-academy/agent-integrations/package.json', import.meta.url), 'utf8'));
console.log(`Human+ form bridge controls passed: ${checks.length} groups on agent-integrations ${version}; no transport or provider calls.`);
