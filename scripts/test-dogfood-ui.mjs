import assert from 'node:assert/strict';
import { build } from 'vite';
import { resolve } from 'node:path';
import { chromium } from 'playwright';

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
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(15000);
    page.on('pageerror', error => { failures.push(error.message); console.error(`Browser exception: ${error.message}`); });
    let runs = [structuredClone(baseRun)];
    let collectionPolled = false;
    let component = 'Lab/Consensus';
    const props = () => component === 'Lab/Consensus'
        ? { runs, errors: {} }
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
        if (path === '/lab/team/readiness') return route.fulfill({ json: { lanes: [
            { agent: 'prism.py', language: 'py', port_version: 'fixture', capabilities: { consensus: { ready: false, reason: 'No provider credentials configured.' }, benchmark: { ready: false, reason: 'This port has no benchmark lane executor (G-10).' } } },
        ] } });
        if (path === '/lab/agent') return route.fulfill({ json: { messages: [], drafts: [] } });
        if (request.method() === 'POST' && path === '/lab/consensus') {
            const input = request.postDataJSON();
            assert.equal(input.question, 'New question about alignment');
            assert.equal(input.evidence, 'Expected before the run');
            runs = [{ ...structuredClone(baseRun), id: 'new-run', question: input.question, status: 'collecting', responses: [], tally: { agents: 0, responded: 0, unavailable: 0, dissenting: 0 } }, ...runs];
        } else if (request.method() === 'GET' && path === '/lab/consensus' && runs[0].status === 'collecting') {
            collectionPolled = true;
            runs[0] = { ...runs[0], status: 'awaiting_review', responses: [
                { id: 'answer', agent: 'prism.ts', language: 'ts', status: 'responded', answer: '<script>window.injected=true</script> Retain the objection.', failure_reason: null, confidence: null, evidence: ['receipt'], dissent: 'The agents may share a blind spot.' },
            ], tally: { agents: 1, responded: 1, unavailable: 0, dissenting: 1 } };
        } else if (request.method() === 'POST' && path === '/lab/consensus/new-run/review') {
            assert.equal(request.postDataJSON().synthesis, 'Keep dissent explicit.');
            runs[0] = { ...runs[0], status: 'reviewed', synthesis: 'Keep dissent explicit.' };
        } else if (!['/lab/consensus', '/lab/benchmarks'].includes(path)) {
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
    await page.getByRole('heading', { name: 'New question about alignment' }).waitFor();
    await page.getByText('The agents may share a blind spot.', { exact: false }).waitFor();
    assert.equal(collectionPolled, true, 'A queued collection must poll and render the worker result.');
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
    assert.deepEqual(failures, [], 'Real pages must mount without browser exceptions.');
    console.log('Dogfood browser controls passed: collection, selection, review, dissent, escaped output, readiness and benchmark design drawer.');
} finally {
    await browser.close();
}
