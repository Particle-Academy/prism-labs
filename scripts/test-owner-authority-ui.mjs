import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { build } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { chromium } from 'playwright';

assert(!fs.existsSync('.env'), 'Use an owned credential-free checkout.');
const directory = path.resolve(process.argv[2] ?? 'storage/framework/testing/owner-authority-ui');
assert(!fs.existsSync(directory), 'Preserve prior browser evidence and use a fresh output directory.');
fs.mkdirSync(directory, { recursive: true });
const virtual = '\0owner-authority-ui';
const result = await build({
    configFile: false, logLevel: 'error',
    plugins: [tailwindcss(), {
        name: 'owner-authority-native-fixture',
        resolveId: id => id === 'owner-authority-ui' ? virtual : undefined,
        load: id => id === virtual ? `import ${JSON.stringify(path.resolve('resources/js/app.tsx').replaceAll('\\', '/'))};
            import { createDomNavigationAgent, OFF_LIMITS_ERROR } from '@particle-academy/agent-integrations/dom-navigation';
            window.__ownerTestDom = createDomNavigationAgent(); window.__ownerTestFenceError = OFF_LIMITS_ERROR;` : undefined,
    }],
    build: { write: false, minify: false, rollupOptions: { input: { app: 'owner-authority-ui', styles: path.resolve('resources/css/app.css') } } },
});
const output = (Array.isArray(result) ? result[0] : result).output;
const entry = output.find(item => item.type === 'chunk' && item.isEntry && item.name === 'app');
assert(entry);
const files = new Map(output.map(item => [`/${item.fileName}`, item.type === 'chunk' ? item.code : item.source]));
const css = output.filter(item => item.fileName.endsWith('.css')).map(item => `<link rel="stylesheet" href="/${item.fileName}">`).join('');
const browser = await chromium.launch({ headless: true });
const errors = [], groups = [];
const traffic = [];
let page;
let signedIn = false, intent = null, paidCalls = 0, drafted = 0, approved = 0, givenGo = 0, revoked = 0;
const delegatedOnly = process.argv[3] === 'delegated-only';
const conversationOnly = process.argv[3] === 'conversation-only';
let attached = false;
const frozenReport = 'Reviewed finding\nUntrusted <img src=x onerror="throw new Error(\'injected\')">\n' + 'reviewed-content-'.repeat(80);
const csrf = () => signedIn ? 'owned-csrf-after-native-login' : 'owned-csrf-before-login';
const fixtureProps = pathname => pathname === '/lab/owner'
    ? { configured: true, isOwner: signedIn, csrfToken: csrf(), errors: {} }
    : pathname === '/lab/chat' ? { version: 'fixture', providers: [{ key: 'anthropic', label: 'Anthropic', model: 'owned-model', modality: 'text', requiresKey: true, configured: true, described: true, env: 'ANTHROPIC_API_KEY' }], undescribed: [], phoenixUrl: 'https://diagnostics.invalid', capabilities: { mode: attached ? 'both' : 'none', browser: attached ? { state: 'attached' } : null, human_plus: attached ? { state: 'attached' } : null }, errors: {} }
    : pathname === '/lab/team' ? { version: 'fixture', learnings: [], parity: null, errors: {} }
    : pathname === '/lab/evidence' ? { open: [{ ref: '0L-9001', title: 'Reviewed finding', severity: 'info', severity_label: 'Info', filed_by: 'owned-fixture', what_was_learned: 'Reviewed observation', evidence: 'Reviewed evidence', why_it_matters: 'Reviewed explanation', what_should_change: null, path: 'owned/report.md', sent_at: null, acted_at: null, acted_note: null }], closed: [], errors: {} } : { errors: {} };
const fixtureComponent = pathname => pathname === '/lab/owner' ? 'Lab/Owner' : pathname === '/lab/team' ? 'Lab/Team' : pathname === '/lab/evidence' ? 'Lab/Evidence' : pathname === '/lab/chat' ? 'Lab/Chat' : 'Lab/ProviderProbes';
const counters = () => ({ paidCalls, drafted, approved, givenGo, revoked });

try {
    page = await browser.newPage({ viewport: { width: 1365, height: 900 }, offline: true, serviceWorkers: 'block' });
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { const url = new URL(request.url()); if (!files.has(url.pathname)) traffic.push({ method: request.method(), path: url.pathname }); });
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url()), pathname = url.pathname;
        if (url.origin !== 'https://lab.test') { errors.push('Unexpected external origin: ' + url.origin); return route.abort('blockedbyclient'); }
        if (files.has(pathname)) return route.fulfill({ contentType: pathname.endsWith('.css') ? 'text/css' : pathname.endsWith('.woff2') ? 'font/woff2' : 'text/javascript', body: typeof files.get(pathname) === 'string' ? files.get(pathname) : Buffer.from(files.get(pathname)) });
        if (pathname === '/lab/owner/login' && request.method() === 'POST') {
            const fields = new URLSearchParams(request.postData());
            assert.equal(fields.get('email'), 'owner@fixture.invalid');
            assert.equal(fields.get('password'), 'synthetic-ui-password');
            assert.equal(fields.get('_token'), csrf());
            assert.deepEqual([...fields.keys()].sort(), ['_token', 'email', 'password']);
            signedIn = true;
            // page.route intercepts only the first URL in an HTTP redirect.
            // Assert the native POST here, then explicitly load the next page;
            // the PHP integration suite verifies the real redirect response.
            return route.fulfill({ contentType: 'text/html', body: 'Native login POST received by isolated fixture.' });
        }
        if (pathname === '/lab/owner/logout' && request.method() === 'POST') {
            assert.equal(new URLSearchParams(request.postData()).get('_token'), csrf());
            signedIn = false;
            return route.fulfill({ contentType: 'text/html', body: 'Native logout POST received by isolated fixture.' });
        }
        if (pathname === '/lab/run-intents' && request.method() === 'POST') {
            assert(signedIn); assert.equal(request.headers()['x-csrf-token'], csrf());
            const input = request.postDataJSON();
            assert.equal(input.action, delegatedOnly ? 'evidence.send' : conversationOnly ? 'chat.run' : 'provider.research'); assert.equal(input.resource, 'lab:agent'); assert.equal(input.cap_usd, '0.25');
            drafted++;
            intent = { id: '00000000-0000-4000-8000-000000000001', action: input.action, resource: input.resource, status: 'draft', digest: 'a'.repeat(64), cap_microusd: 250000, expires_at: '2099-01-01T00:00:00Z', payload: { input: input.payload } };
            if (delegatedOnly) intent.payload.nudge = { terminal: 'owned-terminal', message: frozenReport, reports: [{ id: '1', ref: '0L-9001', message: frozenReport }] };
            if (conversationOnly) {
                assert.deepEqual(input.payload, { provider: 'anthropic', model: 'owned-model', feature: 'tools', prompt: 'Owned conversation question' });
                intent.payload.session = { thread_id: 'owned-native-thread', browser_attached: true, human_plus_attached: true, effective: { provider: 'anthropic', model: 'owned-model', mode: 'chat', max_steps: 3, tools: ['owned_tool'] } };
            }
            return route.fulfill({ status: 201, json: intent });
        }
        if (pathname.startsWith('/lab/run-intents/') && request.method() === 'POST') {
            assert(signedIn); assert(intent); assert.equal(request.headers()['x-csrf-token'], csrf());
            const operation = pathname.split('/').at(-1), input = request.postDataJSON();
            assert.equal(pathname.split('/').at(-2), intent.id);
            if (operation === 'approve') { assert.equal(intent.status, 'draft'); assert.deepEqual(input, { digest: intent.digest }); approved++; intent.status = 'approved'; }
            else if (operation === 'go') { assert.equal(intent.status, 'approved'); assert.deepEqual(input, { digest: intent.digest, cap_usd: '0.25', confirmation: 'GO' }); givenGo++; intent.status = 'ready'; }
            else { assert.equal(operation, 'revoke'); revoked++; intent.status = 'revoked'; }
            return route.fulfill({ json: intent });
        }
        if (pathname === '/lab/provider-probes/research' && request.method() === 'POST') {
            assert.equal(intent?.status, 'ready'); assert.equal(request.headers()['x-lab-run-id'], intent.id);
            assert.deepEqual(request.postDataJSON(), intent.payload.input, 'The actual paid body is the exact frozen request.');
            paidCalls++; intent.status = 'running';
            return route.fulfill({ json: { ok: true, answer: 'Owned fixture result.' } });
        }
        if (pathname === '/lab/team/roster') return route.fulfill({ json: { roster: ['ts', 'py'].map(language => ({ name: 'prism.' + language, language, state: 'live', state_label: 'Live', repo: 'prism-' + language, addressable: true, reachable: true, note: null, reported: { port_version: 'fixture', can_reason: false, provider_key_present: true, provider_available: true, paid_call_refusal: 'paid_call_not_authorised' } })) } });
        if (pathname === '/lab/team/harness' || pathname === '/lab/team/ecosystem') return route.fulfill({ json: { lanes: [], families: {}, keys_agree: false } });
        if (pathname === '/lab/agent' && request.method() === 'GET') return route.fulfill({ json: { messages: [], drafts: [] } });
        if (!['/lab/owner', '/lab/provider-probes', '/lab/team', '/lab/evidence', '/lab/chat'].includes(pathname) || request.method() !== 'GET') { errors.push('Unexpected fixture request: ' + request.method() + ' ' + pathname); return route.fulfill({ status: 404, body: 'Unexpected fixture request' }); }
        const inertia = { component: fixtureComponent(pathname), props: fixtureProps(pathname), url: pathname, version: 'owner-native-ui', clearHistory: false, encryptHistory: false };
        const encoded = JSON.stringify(inertia).replaceAll('<', '\\u003c');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html class="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="${csrf()}">${css}</head><body><div id="app"></div><script type="application/json" data-page="app">${encoded}</script><script type="module" src="/${entry.fileName}"></script></body></html>` });
    });

    async function assertFenced(action, handle, value) {
        const result = await page.evaluate(({ action, handle, value }) => ({ result: window.__ownerTestDom[action](handle, value), expected: window.__ownerTestFenceError }), { action, handle, value });
        assert.deepEqual(result.result, { ok: false, error: result.expected });
    }

    if (conversationOnly) {
        signedIn = true;
        await page.goto('https://lab.test/lab/chat');
        await page.locator('#lab-browser-open').waitFor();
        await assertFenced('click', 'lab-browser-open');
        await assertFenced('click', 'lab-human-plus-join');
        await assertFenced('setField', 'lab-human-plus-token', 'agent-must-not-insert-invitation');
        assert.equal(await page.locator('#lab-human-plus-token').inputValue(), '');
        assert.equal(await page.evaluate(() => JSON.stringify(window.__ownerTestDom.describe()).includes('lab-human-plus-token')), false);
        attached = true;
        await page.goto('https://lab.test/lab/chat');
        await page.locator('#lab-browser-close').waitFor();
        await assertFenced('click', 'lab-browser-close');
        await assertFenced('click', 'lab-human-plus-leave');
        assert.equal(traffic.filter(item => item.path.startsWith('/lab/capabilities')).length, 0);
        groups.push('installed-agent-guards-refuse-capability-grants-removal-and-invitation-entry');
        await page.locator('form textarea').fill('Owned conversation question');
        await page.getByRole('button', { name: 'Run generation →', exact: true }).click();
        await page.getByLabel('USD cap for this run (up to $5)', { exact: true }).fill('0.25');
        await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).click();
        const settings = page.getByLabel('Frozen conversation settings', { exact: true });
        await settings.waitFor();
        for (const text of ['Provider: anthropic', 'Model: owned-model', 'Mode: chat', 'Maximum steps: 3', 'Thread: owned-native-thread', 'Browser attached: Yes', 'Human+ attached: Yes', 'Mode tools: owned_tool']) assert((await settings.textContent()).includes(text), text);
        await assertFenced('click', 'paid-run-approve');
        assert.deepEqual(counters(), { paidCalls: 0, drafted: 1, approved: 0, givenGo: 0, revoked: 0 });
        await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-react-fancy-modal]')).opacity === '1');
        await page.screenshot({ path: path.join(directory, 'conversation-desktop.png'), animations: 'disabled' });
        await page.setViewportSize({ width: 390, height: 844 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        await page.screenshot({ path: path.join(directory, 'conversation-mobile.png'), animations: 'disabled' });
        groups.push('frozen-effective-model-mode-steps-thread-capabilities-visible-on-desktop-mobile', 'native-approval-fence-refuses-agent');
        await page.getByRole('button', { name: 'Cancel run', exact: true }).click();
        await page.getByText('The paid run was cancelled before launch.', { exact: true }).waitFor();
        assert.deepEqual(counters(), { paidCalls: 0, drafted: 1, approved: 0, givenGo: 0, revoked: 1 });
        assert.deepEqual(errors, []);
        groups.push('cancel-revokes-without-inference-or-capability-request');
        const receipt = { status: 'PASS', groups, counters: counters(), browserErrors: errors, actualAppAndFancyPrimitives: true, nativeInstalledDomNavigationGuards: true, nativeAuthenticationAndServerSnapshotsCoveredSeparatelyByPhp: true, allNetworkInterceptedNoServerDatabaseOrProvider: true, browserOfflineAndServiceWorkersBlocked: true };
        fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
        console.log(JSON.stringify(receipt));
    } else if (delegatedOnly) {
        // Focused rendering/fence controls. Native authentication and real
        // server-side snapshots are exercised by separate PHP/owner suites.
        signedIn = true;
        await page.goto('https://lab.test/lab/evidence');
        await page.getByRole('button', { name: 'Send 1 to agent', exact: true }).click();
        await page.getByLabel('USD cap for this run (up to $5)', { exact: true }).fill('0.25');
        await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).click();
        const report = page.getByLabel('Frozen delegated message', { exact: true });
        await report.waitFor();
        assert.equal(await report.textContent(), frozenReport);
        assert.equal(await report.locator('img').count(), 0);
        await page.getByText('Deliver to local terminal: owned-terminal', { exact: true }).waitFor();
        await assertFenced('click', 'paid-run-approve');
        assert.deepEqual(counters(), { paidCalls: 0, drafted: 1, approved: 0, givenGo: 0, revoked: 0 });
        await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-react-fancy-modal]')).opacity === '1');
        await page.screenshot({ path: path.join(directory, 'delegated-desktop.png'), fullPage: true, animations: 'disabled' });
        await page.setViewportSize({ width: 390, height: 844 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        await page.screenshot({ path: path.join(directory, 'delegated-mobile.png'), fullPage: true, animations: 'disabled' });
        await page.getByRole('button', { name: 'Cancel run', exact: true }).click();
        await page.getByText('The paid run was cancelled before launch.', { exact: true }).waitFor();
        assert.deepEqual(counters(), { paidCalls: 0, drafted: 1, approved: 0, givenGo: 0, revoked: 1 });
        assert.deepEqual(errors, []);
        groups.push('exact-frozen-report-and-terminal-visible', 'untrusted-report-is-text', 'native-approval-fence-refuses-agent', 'desktop-mobile-no-horizontal-overflow', 'cancel-revokes-without-delivery');
        const receipt = { status: 'PASS', groups, counters: counters(), browserErrors: errors, focusedVisualAndFenceOnly: true, nativeAuthenticationNotRepeated: true, allNetworkInterceptedNoServerDatabaseOrProvider: true, browserOfflineAndServiceWorkersBlocked: true };
        fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
        console.log(JSON.stringify(receipt));
    } else if (process.argv[3] === 'visual-only') {
        await page.goto('https://lab.test/lab/owner');
        await page.getByLabel('Password', { exact: true }).waitFor();
        await page.screenshot({ path: path.join(directory, 'owner-desktop.png'), fullPage: true, animations: 'disabled' });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(directory, 'owner-mobile.png'), fullPage: true, animations: 'disabled' });
        groups.push('stable-owner-desktop-mobile-render');
        await page.goto('https://lab.test/lab/provider-probes');
        await page.getByRole('button', { name: 'Run research · paid call', exact: true }).click();
        await page.locator('[data-react-fancy-modal]').waitFor();
        await page.waitForFunction(() => getComputedStyle(document.querySelector('[data-react-fancy-modal]')).opacity === '1');
        const modalBounds = await page.locator('[data-react-fancy-modal]').boundingBox();
        assert(modalBounds && modalBounds.x >= 0 && modalBounds.y >= 0 && modalBounds.x + modalBounds.width <= 390 && modalBounds.y + modalBounds.height <= 844);
        await page.screenshot({ path: path.join(directory, 'approval-mobile.png'), animations: 'disabled' });
        await page.setViewportSize({ width: 1365, height: 900 });
        await page.screenshot({ path: path.join(directory, 'approval-desktop.png'), animations: 'disabled' });
        assert.deepEqual(counters(), { paidCalls: 0, drafted: 0, approved: 0, givenGo: 0, revoked: 0 });
        assert.deepEqual(errors, []);
        groups.push('stable-fully-visible-modal-within-mobile-viewport');
        const receipt = { status: 'PASS', groups, counters: counters(), browserErrors: errors, visualOnlyNoRepeatedLoginOrGoControls: true, browserOfflineAndServiceWorkersBlocked: true };
        fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
        console.log(JSON.stringify(receipt));
    } else {
    await page.goto('https://lab.test/lab/owner');
    await page.getByLabel('Password', { exact: true }).waitFor();
    if (process.argv[3] !== 'from-login') {
        await assertFenced('setField', 'owner-password', 'agent-must-not-type-this');
        await assertFenced('click', 'owner-login-submit');
        assert.equal(await page.getByLabel('Password', { exact: true }).inputValue(), '');
        assert.equal(await page.evaluate(() => JSON.stringify(window.__ownerTestDom.describe()).includes('owner-password')), false);
        await page.screenshot({ path: path.join(directory, 'owner-desktop.png'), fullPage: true });
        groups.push('native-agent-password-and-login-fences');
    }
    await page.getByLabel('Email', { exact: true }).fill('owner@fixture.invalid');
    await page.getByLabel('Password', { exact: true }).fill('synthetic-ui-password');
    const loginNavigation = page.waitForURL('https://lab.test/lab/owner/login');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await loginNavigation;
    await page.goto('https://lab.test/lab/owner');
    await page.getByText('You are signed in as the configured Lab owner.', { exact: true }).waitFor();
    assert.equal(await page.locator('meta[name="csrf-token"]').getAttribute('content'), csrf());
    assert.equal(paidCalls, 0);
    groups.push('native-form-only-login-and-refreshed-csrf');

    await page.goto('https://lab.test/lab/team');
    await page.getByText('Paid agent calls need run authority, not yet available.', { exact: true }).first().waitFor();
    assert.equal(await page.getByText('Paid agent calls need run authority, not yet available.', { exact: true }).count(), 2);
    assert.equal(await page.getByText('present', { exact: true }).count(), 2);
    assert.equal(await page.getByText('no key', { exact: true }).count(), 0);
    groups.push('honest-paid-agent-unavailability-and-separate-key-presence');

    await page.goto('https://lab.test/lab/provider-probes');
    await page.getByRole('button', { name: 'Run research · paid call', exact: true }).click();
    await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).waitFor();
    assert.deepEqual(counters(), { paidCalls: 0, drafted: 0, approved: 0, givenGo: 0, revoked: 0 });
    await assertFenced('setField', 'paid-run-cap', '5');
    await page.getByLabel('USD cap for this run (up to $5)', { exact: true }).fill('0.25');
    await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).click();
    await page.getByRole('button', { name: 'Approve this scope and cap', exact: true }).waitFor();
    assert.equal(paidCalls, 0); assert.equal(givenGo, 0);
    assert.equal(await page.getByRole('button', { name: 'GO — launch this run', exact: true }).count(), 0);
    await assertFenced('click', 'paid-run-approve');
    assert.equal(approved, 0);
    await page.getByRole('button', { name: 'Approve this scope and cap', exact: true }).click();
    await page.getByRole('button', { name: 'GO — launch this run', exact: true }).waitFor();
    await assertFenced('click', 'paid-run-go');
    assert.equal(givenGo, 0); assert.equal(paidCalls, 0);
    groups.push('draft-approval-go-are-distinct-and-agent-fenced');
    await page.screenshot({ path: path.join(directory, 'approval-desktop.png'), fullPage: true });
    await page.getByRole('button', { name: 'GO — launch this run', exact: true }).click();
    await page.getByText('Owned fixture result.', { exact: true }).waitFor();
    assert.deepEqual(counters(), { paidCalls: 1, drafted: 1, approved: 1, givenGo: 1, revoked: 0 });
    groups.push('one-go-one-exact-payload-post-with-approved-reference');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Run research · paid call', exact: true }).click();
    await page.getByRole('button', { name: 'Prepare exact intent', exact: true }).waitFor();
    await page.screenshot({ path: path.join(directory, 'approval-mobile.png'), fullPage: true });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.getByRole('button', { name: 'Cancel run', exact: true }).click();
    await page.getByText('The paid run was cancelled before launch.', { exact: true }).waitFor();
    assert.equal(paidCalls, 1); assert.equal(drafted, 1);
    groups.push('mobile-review-cancel-before-draft-spends-nothing');
    await page.goto('https://lab.test/lab/owner');
    const logoutNavigation = page.waitForURL('https://lab.test/lab/owner/logout');
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await logoutNavigation;
    await page.goto('https://lab.test/lab/owner');
    await page.getByLabel('Password', { exact: true }).waitFor();
    assert.equal(signedIn, false);
    await page.screenshot({ path: path.join(directory, 'owner-mobile.png'), fullPage: true });
    assert.deepEqual(errors, []);
    groups.push('native-logout-and-no-unexpected-network');
    const receipt = { status: 'PASS', groups, counters: counters(), browserErrors: errors, actualAppAndFancyPrimitives: true, nativeInstalledDomNavigationGuards: true, allNetworkInterceptedNoServerDatabaseOrProvider: true, browserOfflineAndServiceWorkersBlocked: true, nativeRedirectStatusTestedInPhpNotBrowser: true, routingConstraint: 'https://playwright.dev/docs/api/class-page#page-route' };
    fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
    console.log(JSON.stringify(receipt));
    }
} catch (failure) {
    const formDiagnostics = await page?.locator('form').evaluateAll(forms => forms.map(form => ({ action: form.getAttribute('action'), method: form.method, fields: [...form.elements].map(field => ({ tag: field.tagName, name: field.name, type: field.type, valid: field.validity?.valid, hasValue: Boolean(field.value), disabled: field.disabled })) }))).catch(() => null);
    await page?.screenshot({ path: path.join(directory, 'failure.png'), fullPage: true }).catch(() => {});
    fs.writeFileSync(path.join(directory, 'failed.json'), JSON.stringify({ status: 'RED_RETAINED', groups, counters: counters(), signedIn, traffic, formDiagnostics, browserErrors: errors, error: failure.message }, null, 2) + '\n');
    throw failure;
} finally { await browser.close(); }
