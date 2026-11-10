import assert from 'node:assert/strict';
import { build } from 'vite';
import { resolve } from 'node:path';
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const acpRelease = JSON.parse(readFileSync(new URL('./acp-release.json', import.meta.url), 'utf8'));

// Real entry point, pages, Inertia forms and Fancy providers. The browser's
// network is intercepted: no web server, database, credentials or model calls.
console.log('Building the real application for the browser controls.');
const result = await build({
    configFile: false, logLevel: 'error',
    build: { write: false, minify: false, rollupOptions: { input: resolve('resources/js/app.tsx') } },
});
const output = (Array.isArray(result) ? result[0] : result).output;
const entry = output.find(item => item.type === 'chunk' && item.isEntry);
const files = new Map(output.map(item => [`/${item.fileName}`, item.type === 'chunk' ? item.code : item.source]));
const css = output.filter(item => item.fileName.endsWith('.css')).map(item => `<link rel="stylesheet" href="/${item.fileName}">`).join('');
console.log('Application bundled; starting isolated browser.');
const browser = await chromium.launch({ headless: true });
const failures = [];
const baseRun = {
    id: 'old-run', question: 'Previous question', status: 'awaiting_review', synthesis: null,
    evidence_digest: 'fixture-digest', reviewed_at: null, abandoned_at: null, abandon_reason: null, created_at: null,
    tally: { agents: 2, responded: 0, unavailable: 2, dissenting: 0 }, learning: null,
    responses: [{ id: 'offline', agent: 'prism.py', language: 'py', status: 'unavailable', answer: null, failure_reason: 'No provider credentials configured.', evidence: [], confidence: null, dissent: null }],
};
try {
    const page = await browser.newPage({ offline: true, serviceWorkers: 'block' });
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(15000);
    page.on('pageerror', error => { failures.push(error.message); console.error(`Browser exception: ${error.message}`); });
    const paidRefusal = 'Paid agent calls need run authority, not yet available.';
    // Recorded answers exercise display/review. New local collections below
    // retain the native refusal until a reviewed remote authority transport exists.
    const recordedRun = { ...structuredClone(baseRun), id: 'recorded-run', question: 'Recorded alignment question',
        responses: [{ id: 'recorded-answer', agent: 'prism.ts', language: 'ts', status: 'responded',
            answer: '<script>window.injected=true</script> Retain the objection.', failure_reason: null,
            confidence: null, evidence: ['recorded receipt'], dissent: 'The agents may share a blind spot.' }],
        tally: { agents: 1, responded: 1, unavailable: 0, dissenting: 1 } };
    let runs = [structuredClone(baseRun), recordedRun];
    let collectionPolled = false;
    // Authenticated synthetic session only; native login/403/CSRF are PHP controls.
    const ownerSignedIn = true, intents = new Map();
    let prepared = 0, approved = 0, givenGo = 0, paidPosts = 0;
    function consumeIntent(request, action, input) {
        assert(ownerSignedIn);
        const intent = intents.get(request.headers()['x-lab-run-id']);
        assert(intent && intent.status === 'ready', 'No paid POST is valid before its distinct recorded GO.');
        assert.equal(intent.action, action); assert.equal(intent.resource, 'lab:agent');
        assert.deepEqual(input, intent.payload.input, 'The actual POST must match the exact prepared JSON.');
        intent.status = 'running'; paidPosts++;
    }
    async function reviewAndGo(action, input) {
        const before = paidPosts;
        await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).waitFor();
        assert.equal(paidPosts, before);
        await page.getByLabel('USD cap for this run (up to $5)', { exact: true }).fill('0.25');
        await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).click();
        await page.getByRole('button', { name: 'Approve this scope and cap', exact: true }).waitFor();
        assert.equal(paidPosts, before);
        assert.deepEqual(JSON.parse(await page.getByLabel('Frozen request', { exact: true }).textContent()), input);
        assert.equal([...intents.values()].at(-1).action, action);
        await page.getByRole('button', { name: 'Approve this scope and cap', exact: true }).click();
        await page.getByRole('button', { name: 'GO — launch this run', exact: true }).waitFor();
        assert.equal(paidPosts, before);
        await page.getByRole('button', { name: 'GO — launch this run', exact: true }).click();
    }
    const oldAcp = {
        id: 'codex-old', provider: 'codex', status: 'finished', pending: false, prompt: 'Previous Codex turn',
        model: 'prior-codex-model', resume_session_id: null, cli_session_id: 'owned-codex-session', stop_reason: 'end_turn',
        error: null, updates: [{ sessionUpdate: 'agent_message_chunk', content: { text: 'Previous Codex answer' } }],
        history_updates: [], handshake: null, process: null, created_at: null,
    };
    let acpTurns = [structuredClone(oldAcp)];
    let acpSubmissions = 0;
    let acpPolls = 0;
    let component = 'Lab/Consensus';
    const props = () => component === 'Lab/Consensus'
        ? { runs, errors: {} }
        : component === 'Lab/Acp'
            ? { version: 'fixture', acpVersion: acpRelease.tag, model: 'haiku', models: { claude: 'haiku', codex: '' }, turns: acpTurns, errors: {} }
            : { specs: [], runs: [], compactionRuns: [], providerAggregateCount: 0, errors: {} };
    const inertia = url => ({ component, props: props(), url, version: 'dogfood-fixture', clearHistory: false, encryptHistory: false });
    await page.route('**/*', async route => {
        const request = route.request();
        if (new URL(request.url()).origin !== 'https://lab.test') {
            failures.push('Unexpected external request: ' + new URL(request.url()).origin);
            return route.abort('blockedbyclient');
        }
        const path = new URL(request.url()).pathname;
        if (files.has(path)) return route.fulfill({ contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript', body: files.get(path) });
        if (request.method() === 'POST' && path.startsWith('/lab/run-intents')) {
            assert(ownerSignedIn); assert.equal(request.headers()['x-csrf-token'], 'fixture-only');
            const input = request.postDataJSON();
            if (path === '/lab/run-intents') {
                assert.deepEqual(Object.keys(input).sort(), ['action', 'cap_usd', 'payload', 'resource']);
                assert.equal(input.cap_usd, '0.25'); assert.equal(input.resource, 'lab:agent');
                assert(['consensus.collect', 'acp.send'].includes(input.action));
                prepared++;
                const intent = { id: `00000000-0000-4000-8000-${String(prepared).padStart(12, '0')}`,
                    action: input.action, resource: input.resource, status: 'draft', digest: 'a'.repeat(64),
                    cap_microusd: 250000, expires_at: '2099-01-01T00:00:00Z', payload: { input: input.payload } };
                if (input.action === 'acp.send') intent.payload.acp = { provider: input.payload.provider,
                    model: input.payload.model, resume_session_id: input.payload.continue ? 'owned-new-session' : null };
                intents.set(intent.id, intent);
                return route.fulfill({ status: 201, json: intent });
            }
            const [, id, operation] = /^\/lab\/run-intents\/([^/]+)\/(approve|go)$/.exec(path) ?? [];
            const intent = intents.get(id); assert(intent, 'Approval must select a prepared intent.');
            assert.equal(input.digest, intent.digest);
            if (operation === 'approve') {
                assert.equal(intent.status, 'draft'); assert.deepEqual(Object.keys(input), ['digest']);
                intent.status = 'approved'; approved++;
            } else {
                assert.equal(intent.status, 'approved');
                assert.deepEqual(input, { digest: intent.digest, cap_usd: '0.25', confirmation: 'GO' });
                intent.status = 'ready'; givenGo++;
            }
            return route.fulfill({ json: intent });
        }
        if (path === '/lab/team/readiness') return route.fulfill({ json: { lanes: [
            { agent: 'prism.py', language: 'py', port_version: 'fixture', capabilities: { consensus: { ready: false, reason: 'No provider credentials configured.' }, benchmark: { ready: false, reason: 'This port has no benchmark lane executor (G-10).' } } },
        ] } });
        if (path === '/lab/agent') return route.fulfill({ json: { messages: [], drafts: [] } });
        if (path === '/lab/acp' && request.method() === 'POST') {
            const input = request.postDataJSON();
            consumeIntent(request, 'acp.send', input);
            acpSubmissions++;
            assert.deepEqual(input, { provider: 'codex', prompt: acpSubmissions === 1 ? 'Codex browser control' : 'Continuation browser control',
                model: 'fixture-model', continue: acpSubmissions === 1 ? null : 'codex-new' });
            const turn = { ...structuredClone(oldAcp), id: acpSubmissions === 1 ? 'codex-new' : 'codex-resumed',
                status: 'queued', pending: true, prompt: input.prompt, model: input.model, updates: [], cli_session_id: null,
                resume_session_id: acpSubmissions === 1 ? null : 'owned-new-session' };
            acpTurns = [turn, ...acpTurns];
            return route.fulfill({ status: 202, json: turn });
        }
        if (path.startsWith('/lab/acp/turn/')) {
            acpPolls++;
            const turn = acpTurns.find(item => item.id === path.split('/').at(-1));
            assert(turn, 'poll selects the queued fixture turn');
            turn.status = 'finished'; turn.pending = false; turn.cli_session_id = 'owned-new-session';
            turn.updates = [{ sessionUpdate: 'agent_message_chunk', content: { text: turn.id === 'codex-resumed'
                ? '<script>window.acpInjected=true</script> follow-up answer' : 'new answer only' } }];
            turn.history_updates = turn.id === 'codex-resumed' ? [{ sessionUpdate: 'agent_message_chunk', content: { text: 'new answer only' } }] : [];
            return route.fulfill({ json: turn });
        }
        if (request.method() === 'POST' && path === '/lab/consensus') {
            const input = request.postDataJSON();
            consumeIntent(request, 'consensus.collect', input);
            assert.equal(input.question, 'New question about alignment');
            assert.equal(input.evidence, 'Expected before the run');
            runs = [{ ...structuredClone(baseRun), id: 'new-run', question: input.question, status: 'collecting', responses: [], tally: { agents: 0, responded: 0, unavailable: 0, dissenting: 0 } }, ...runs];
        } else if (request.method() === 'GET' && path === '/lab/consensus' && runs[0].status === 'collecting') {
            collectionPolled = true;
            runs[0] = { ...runs[0], status: 'awaiting_review', responses: [
                { id: 'new-unavailable', agent: 'prism.ts', language: 'ts', status: 'unavailable', answer: null,
                    failure_reason: paidRefusal, confidence: null, evidence: [], dissent: null },
            ], tally: { agents: 1, responded: 0, unavailable: 1, dissenting: 0 } };
        } else if (request.method() === 'POST' && path === '/lab/consensus/recorded-run/review') {
            assert.equal(request.postDataJSON().synthesis, 'Keep dissent explicit.');
            runs = runs.map(run => run.id === 'recorded-run' ? { ...run, status: 'reviewed', synthesis: 'Keep dissent explicit.' } : run);
        } else if (!['/lab/consensus', '/lab/benchmarks', '/lab/acp'].includes(path)) {
            return route.fulfill({ status: 404, body: 'Unexpected fixture request' });
        }
        if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, contentType: 'application/json', body: JSON.stringify(inertia(path)) });
        const data = JSON.stringify(inertia(path)).replaceAll('<', String.fromCharCode(92) + 'u003c');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta name="csrf-token" content="fixture-only">${css}</head><body><div id="app"></div><script type="application/json" data-page="app">${data}</script><script type="module" src="/${entry.fileName}"></script></body></html>` });
    });
    await page.goto('https://lab.test/lab/consensus');
    console.log('Consensus fixture opened.');
    await page.getByRole('heading', { name: 'Previous question' }).waitFor();
    await page.getByRole('region', { name: 'Language agent readiness' }).getByText('No provider credentials configured.', { exact: false }).waitFor();
    await page.getByRole('button', { name: 'Request consensus', exact: true }).click();
    await page.getByLabel('Question', { exact: true }).fill('New question about alignment');
    await page.getByLabel('Evidence brief', { exact: false }).fill('Expected before the run');
    await page.getByRole('button', { name: 'Start collection', exact: true }).click();
    await reviewAndGo('consensus.collect', { question: 'New question about alignment', evidence: 'Expected before the run' });
    await page.getByRole('heading', { name: 'New question about alignment' }).waitFor();
    await page.getByText(paidRefusal, { exact: true }).waitFor();
    assert.equal(collectionPolled, true, 'A queued collection must poll and render the worker result.');
    await page.locator('button.lab-consensus-row').filter({ hasText: 'Recorded alignment question' }).click();
    await page.getByText('The agents may share a blind spot.', { exact: false }).waitFor();
    assert.equal(await page.evaluate(() => window.injected), undefined, 'Model output must remain escaped text.');
    await page.getByLabel('Prism.php synthesis', { exact: false }).fill('Keep dissent explicit.');
    await page.getByRole('button', { name: 'Mark reviewed', exact: true }).click();
    await page.locator('.lab-synthesis').getByText('Keep dissent explicit.', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'Mark reviewed', exact: true }).waitFor({ state: 'detached' });
    assert.equal(await page.getByRole('button', { name: 'Mark reviewed', exact: true }).count(), 0, 'The selected run must reflect the reviewed props immediately.');
    component = 'Lab/Benchmarks';
    await page.goto('https://lab.test/lab/benchmarks');
    await page.getByText('This port has no benchmark lane executor (G-10).', { exact: false }).waitFor();
    await page.getByRole('button', { name: 'Open Overseer', exact: true }).first().click();
    await page.getByPlaceholder('Tell PLab what you want to learn or test…').waitFor();
    component = 'Lab/Acp';
    await page.goto('https://lab.test/lab/acp');
    const provider = page.getByLabel('Provider', { exact: true });
    const model = page.getByLabel('Model', { exact: true });
    assert.equal(await provider.inputValue(), 'claude', 'Claude remains the default');
    assert.equal(await model.inputValue(), 'haiku');
    await page.getByRole('button', { name: 'Continue this conversation', exact: true }).click();
    assert.equal(await provider.inputValue(), 'codex', 'Continue restores the source provider');
    assert.equal(await model.inputValue(), 'prior-codex-model', 'Continue restores the source model');
    await provider.selectOption('claude');
    await page.getByText('Continuing Codex', { exact: false }).waitFor({ state: 'hidden' });
    assert.equal(await model.inputValue(), 'haiku');
    await provider.selectOption('codex');
    assert.equal(await model.inputValue(), '', 'Codex uses its native default unless a model is chosen');
    await model.fill('fixture-model');
    await page.getByLabel('Prompt', { exact: true }).fill('Codex browser control');
    await page.getByRole('button', { name: 'Run turn', exact: true }).click();
    await reviewAndGo('acp.send', { prompt: 'Codex browser control', provider: 'codex', model: 'fixture-model', continue: null });
    const firstAcp = page.getByText('Codex browser control', { exact: true }).locator('..');
    await firstAcp.getByTestId('acp-answer').waitFor();
    assert.equal(await firstAcp.getByTestId('acp-answer').textContent(), 'new answer only');
    await firstAcp.getByRole('button', { name: 'Continue this conversation', exact: true }).click();
    await page.getByLabel('Prompt', { exact: true }).fill('Continuation browser control');
    await page.getByRole('button', { name: 'Run turn', exact: true }).click();
    await reviewAndGo('acp.send', { prompt: 'Continuation browser control', provider: 'codex', model: 'fixture-model', continue: 'codex-new' });
    const resumedAcp = page.getByText('Continuation browser control', { exact: true }).locator('..');
    await resumedAcp.getByTestId('acp-answer').waitFor();
    assert.equal(await resumedAcp.getByTestId('acp-answer').textContent(), '<script>window.acpInjected=true</script> follow-up answer');
    await resumedAcp.getByText('Replayed conversation history', { exact: true }).waitFor();
    assert.equal(await page.evaluate(() => window.acpInjected), undefined, 'ACP model output remains escaped text');
    assert.equal(acpSubmissions, 2); assert(acpPolls >= 2);
    assert.equal(paidPosts, 3); assert.equal(prepared, 3); assert.equal(approved, 3); assert.equal(givenGo, 3);
    assert([...intents.values()].every(intent => intent.status === 'running'));
    assert.deepEqual(failures, [], 'Real pages must mount without browser exceptions.');
    console.log('Dogfood browser controls passed: exact prepare/approve/GO before each paid POST; new consensus refusal and separately recorded dissent/review; Benchmarks and ACP provider/model/Continue/polling/replay/escaped-output controls. All traffic intercepted; no native authentication, remote agent or provider acceptance claim.');
} finally {
    await browser.close();
}
