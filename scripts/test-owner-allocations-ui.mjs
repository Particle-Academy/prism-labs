import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { build } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { chromium } from 'playwright';

assert(!fs.existsSync('.env'));
const directory = path.resolve(process.argv[2]);
assert(!fs.existsSync(directory));
fs.mkdirSync(directory, { recursive: true });
const virtual = '\0allocation-ui';
const result = await build({ configFile: false, logLevel: 'error', plugins: [tailwindcss(), {
    name: 'owned-allocation-ui', resolveId: id => id === 'allocation-ui' ? virtual : undefined,
    load: id => id === virtual ? `import ${JSON.stringify(path.resolve('resources/js/app.tsx').replaceAll('\\', '/'))};
        import { createDomNavigationAgent, OFF_LIMITS_ERROR } from '@particle-academy/agent-integrations/dom-navigation';
        window.__allocationDom = createDomNavigationAgent(); window.__allocationFence = OFF_LIMITS_ERROR;` : undefined,
}], build: { write: false, minify: false, rollupOptions: { input: { app: 'allocation-ui', styles: path.resolve('resources/css/app.css') } } } });
const output = (Array.isArray(result) ? result[0] : result).output;
const entry = output.find(item => item.type === 'chunk' && item.isEntry && item.name === 'app');
assert(entry);
const files = new Map(output.map(item => [`/${item.fileName}`, item.type === 'chunk' ? item.code : item.source]));
const css = output.filter(item => item.fileName.endsWith('.css')).map(item => `<link rel="stylesheet" href="/${item.fileName}">`).join('');
const browser = await chromium.launch({ headless: true });
const errors = [], traffic = [], groups = [];
const readyId = '019b0000-0000-7000-8000-000000000003';
const runningId = '019b0000-0000-7000-8000-000000000002';
const olderId = '019b0000-0000-7000-8000-000000000001';
const records = [
    { id: readyId, action: 'team.ask <img src=x onerror="throw 1">', status: 'ready', cap_microusd: 2_000_000, expires_at: '2020-01-01T00:00:00Z', consumed_at: null, revoked_at: null, can_revoke: true },
    { id: runningId, action: 'chat.run', status: 'running', cap_microusd: 1_000_000, expires_at: '2099-01-01T00:00:00Z', consumed_at: '2026-01-01T00:00:00Z', revoked_at: null, can_revoke: true },
];
let allocated = 3_000_000, cancelled = 0, refused = 0, reads = 0;
const snapshot = intents => ({ allocated_microusd: allocated, available_microusd: 5_000_000 - allocated, session_limit_microusd: 5_000_000, intents, next_before: intents === records ? runningId : null });
const csrf = 'owned-allocation-csrf';
let page;
try {
    page = await browser.newPage({ viewport: { width: 1365, height: 900 }, offline: true, serviceWorkers: 'block' });
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== 'https://lab.test') { errors.push('Unexpected external origin'); return route.abort('blockedbyclient'); }
        if (files.has(url.pathname)) return route.fulfill({ contentType: url.pathname.endsWith('.css') ? 'text/css' : url.pathname.endsWith('.woff2') ? 'font/woff2' : 'text/javascript', body: typeof files.get(url.pathname) === 'string' ? files.get(url.pathname) : Buffer.from(files.get(url.pathname)) });
        traffic.push({ method: request.method(), path: url.pathname, before: url.searchParams.get('before') });
        if (request.method() === 'GET' && url.pathname === '/lab/run-intents') {
            reads++;
            if (url.searchParams.has('before')) {
                assert.equal(url.searchParams.get('before'), runningId);
                return route.fulfill({ json: snapshot([{ id: olderId, action: 'tests.run', status: 'draft', cap_microusd: 250_000, expires_at: '2099-01-01T00:00:00Z', consumed_at: null, revoked_at: null, can_revoke: true }]) });
            }
            return route.fulfill({ json: snapshot(records) });
        }
        if (request.method() === 'POST' && url.pathname.startsWith('/lab/run-intents/')) {
            assert.equal(url.pathname.split('/').at(-1), 'revoke');
            assert.deepEqual(request.postDataJSON(), {});
            const id = url.pathname.split('/').at(-2);
            if (request.headers()['x-csrf-token'] !== csrf) {
                refused++;
                return route.fulfill({ status: 419, json: { message: 'private-csrf-error-canary' } });
            }
            const record = records.find(item => item.id === id);
            assert(record && record.can_revoke);
            if (!record.consumed_at) allocated -= record.cap_microusd;
            record.status = 'revoked'; record.revoked_at = '2026-01-01T00:00:00Z'; record.can_revoke = false;
            cancelled++;
            return route.fulfill({ json: { id, status: record.status } });
        }
        if (request.method() !== 'GET' || url.pathname !== '/lab/owner') {
            errors.push('Unexpected fixture request: ' + request.method() + ' ' + url.pathname);
            return route.fulfill({ status: 404, body: 'Unexpected request' });
        }
        const owner = !url.searchParams.has('guest');
        const inertia = { component: 'Lab/Owner', props: { configured: true, isOwner: owner, csrfToken: csrf, allocations: owner ? snapshot(records) : null, errors: {} }, url: url.pathname, version: 'owned-allocation-ui', clearHistory: false, encryptHistory: false };
        const encoded = JSON.stringify(inertia).replaceAll('<', '\\u003c');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html class="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="${csrf}">${css}</head><body><div id="app"></div><script type="application/json" data-page="app">${encoded}</script><script type="module" src="/${entry.fileName}"></script></body></html>` });
    });
    await page.goto('https://lab.test/lab/owner');
    const ledger = page.getByRole('region', { name: 'Run allocations', exact: true });
    await ledger.waitFor();
    assert.equal(await ledger.locator('[data-allocation-total]').textContent(), '$3.00');
    assert.equal(await ledger.locator('[data-allocation-available]').textContent(), '$2.00');
    assert.equal(await ledger.locator('img').count(), 0);
    await ledger.getByText('Expired, unused · $2.00', { exact: true }).waitFor();
    await ledger.getByText('Work started · $1.00', { exact: true }).waitFor();
    await page.screenshot({ path: path.join(directory, 'allocations-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.screenshot({ path: path.join(directory, 'allocations-mobile.png'), fullPage: true });
    groups.push('server-allocation-values-and-escaped-native-metadata-visible-on-desktop-and-mobile');
    for (const handle of [`cancel-allocation-${readyId}`, `cancel-allocation-${runningId}`, 'owner-older-allocations']) {
        const response = await page.evaluate(handle => ({ result: window.__allocationDom.click(handle), expected: window.__allocationFence }), handle);
        assert.deepEqual(response.result, { ok: false, error: response.expected });
        assert.equal(await page.evaluate(handle => JSON.stringify(window.__allocationDom.describe()).includes(handle), handle), false);
    }
    assert.equal(traffic.filter(item => item.path.startsWith('/lab/run-intents')).length, 0);
    groups.push('installed-dom-navigation-refuses-cancel-and-pagination-through-live-consent-marker');
    await page.locator(`#cancel-allocation-${readyId}`).click();
    await ledger.locator('[data-allocation-total]').filter({ hasText: '$1.00' }).waitFor();
    await ledger.locator(`[data-run-allocation="${readyId}"]`).getByText('Cancelled · $2.00', { exact: true }).waitFor();
    assert.equal(cancelled, 1); assert.equal(reads, 1);
    groups.push('human-cancel-releases-unused-allocation-and-refreshes-native-read');
    await page.locator(`#cancel-allocation-${runningId}`).click();
    await ledger.locator(`[data-run-allocation="${runningId}"]`).getByText('Cancelled after start · $1.00', { exact: true }).waitFor();
    assert.equal(await ledger.locator('[data-allocation-total]').textContent(), '$1.00');
    assert.equal(cancelled, 2); assert.equal(reads, 2);
    groups.push('human-stop-of-started-work-keeps-recorded-allocation');
    await page.locator('#owner-older-allocations').click();
    await ledger.locator(`[data-run-allocation="${olderId}"]`).waitFor();
    assert.equal(reads, 3); assert.equal(await ledger.locator('[data-run-allocation]').count(), 3);
    assert.equal(await page.locator('#owner-older-allocations').count(), 0);
    groups.push('bounded-older-page-keeps-prior-rows-and-native-total');
    await page.evaluate(() => { document.querySelector('meta[name="csrf-token"]').content = 'expired-ui-token'; });
    await page.locator(`#cancel-allocation-${olderId}`).click();
    await ledger.getByRole('alert').filter({ hasText: 'The owner session changed.' }).waitFor();
    assert.equal(refused, 1); assert.equal(cancelled, 2); assert.equal(reads, 3);
    assert.equal(await ledger.locator('[data-allocation-total]').textContent(), '$1.00');
    assert(!(await page.locator('body').textContent()).includes('private-csrf-error-canary'));
    groups.push('csrf-refusal-keeps-allocation-and-hides-private-diagnostics');
    await page.goto('https://lab.test/lab/owner?guest=1');
    await page.getByLabel('Password', { exact: true }).waitFor();
    assert.equal(await page.getByRole('region', { name: 'Run allocations', exact: true }).count(), 0);
    groups.push('guest-page-receives-no-allocation-snapshot');
    assert.deepEqual(errors, []);
    const receipt = { status: 'PASS', groups, cancelled, refused, reads, paidRequests: 0, unexpectedTraffic: errors, actualAppAndFancyAndInstalledDom: true, phpSeparatelyProvesNativeAuthCsrfSnapshotsAndLedger: true, allHttpInterceptedNoServerDatabaseProviderOrCredentialOperation: true };
    fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
    console.log(JSON.stringify(receipt));
} finally { await page?.close(); await browser.close(); }
