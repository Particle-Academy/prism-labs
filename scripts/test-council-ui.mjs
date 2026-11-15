import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { build } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { chromium } from 'playwright';
import { createCouncilFixture } from './fixtures/council.mjs';

assert(!fs.existsSync('.env'));
const directory = path.resolve(process.argv[2]);
assert(!fs.existsSync(directory));
fs.mkdirSync(directory, { recursive: true });
const virtual = '\0council-ui';
const result = await build({ configFile: false, logLevel: 'error', plugins: [tailwindcss(), {
    name: 'owned-council-ui', resolveId: id => id === 'council-ui' ? virtual : undefined,
    load: id => id === virtual ? `import ${JSON.stringify(path.resolve('resources/js/app.tsx').replaceAll('\\', '/'))};
        import {router} from '@inertiajs/react';
        import {createDomNavigationAgent,OFF_LIMITS_ERROR} from '@particle-academy/agent-integrations/dom-navigation';
        window.__councilDom=createDomNavigationAgent(); window.__councilFence=OFF_LIMITS_ERROR; window.__councilRouter=router;` : undefined,
}], build: { write: false, minify: false, rollupOptions: { input: { app: 'council-ui', styles: path.resolve('resources/css/app.css') } } } });
const output = (Array.isArray(result) ? result[0] : result).output;
const entry = output.find(item => item.type === 'chunk' && item.isEntry && item.name === 'app');
assert(entry);
const files = new Map(output.map(item => [`/${item.fileName}`, item.type === 'chunk' ? item.code : item.source]));
const css = output.filter(item => item.fileName.endsWith('.css')).map(item => `<link rel="stylesheet" href="/${item.fileName}">`).join('');
const csrf = 'synthetic-council-csrf';
const fixture = createCouncilFixture();
const { change, detail, showPath, detailPath, memberPath, memberApi, member, threshold } = fixture;
const browser = await chromium.launch({ headless: true });
const errors = [], traffic = [], groups = [];
let page, posts = 0, accepted = 0;
async function readable(locator) {
    const ratio = await locator.evaluate(element => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const context = canvas.getContext('2d');
        const rgb = color => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            return Array.from(context.getImageData(0, 0, 1, 1).data);
        };
        const foreground = rgb(getComputedStyle(element).color);
        let background = [255, 255, 255, 255];
        for (let node = element; node; node = node.parentElement) {
            const value = rgb(getComputedStyle(node).backgroundColor);
            if (value[3] === 255) { background = value; break; }
        }
        const luminance = channels => channels.slice(0, 3).map(value => {
            const channel = value / 255;
            return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const light = luminance(foreground), dark = luminance(background);
        return (Math.max(light, dark) + 0.05) / (Math.min(light, dark) + 0.05);
    });
    assert(ratio >= 4.5, 'Council text must contrast with its actual host canvas: ' + ratio);
}
try {
    page = await browser.newPage({ viewport: { width: 1280, height: 900 }, offline: true, serviceWorkers: 'block' });
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin !== 'https://lab.test') { errors.push('Unexpected external origin'); return route.abort('blockedbyclient'); }
        if (files.has(url.pathname)) return route.fulfill({ contentType: url.pathname.endsWith('.css') ? 'text/css' : url.pathname.endsWith('.woff2') ? 'font/woff2' : 'text/javascript', body: typeof files.get(url.pathname) === 'string' ? files.get(url.pathname) : Buffer.from(files.get(url.pathname)) });
        traffic.push({ method: request.method(), path: url.pathname });
        if (request.method() === 'GET' && url.pathname === detailPath) return route.fulfill({ json: detail() });
        if (request.method() === 'POST' && url.pathname === detailPath + '/owner/approve-initiation') {
            posts++;
            if (request.headers()['x-csrf-token'] !== csrf) return route.fulfill({ status: 419, json: { error: { code: 'csrf_expired' } } });
            assert.deepEqual(request.postDataJSON(), { note: 'Synthetic human note.' });
            accepted++; change.state = 'round1_open';
            return route.fulfill({ json: { change } });
        }
        if (request.method() === 'GET' && url.pathname === '/council/api/v1/members') return route.fulfill({ json: fixture.memberList() });
        if (request.method() === 'GET' && url.pathname === memberApi) return route.fulfill({ json: fixture.memberDetail() });
        if (['PUT', 'POST'].includes(request.method()) && [memberApi+'/weight', memberApi+'/project', memberApi+'/remove', '/council/api/v1/settings/blocking-threshold'].includes(url.pathname)) {
            assert.equal(request.headers()['x-csrf-token'], csrf);
            const body = request.postDataJSON();
            if (url.pathname.endsWith('/weight')) { assert.deepEqual(body, { weight: 0 }); member.weight = 0; }
            else if (url.pathname.endsWith('/project')) { assert.deepEqual(body, { project_name: 'Reviewed synthetic project', project_description: 'Synthetic owner description.', project_url: 'https://fixture.invalid/project' }); Object.assign(member, body); }
            else if (url.pathname.endsWith('/remove')) { assert.deepEqual(body, { reason: 'Synthetic owner removal.' }); member.removed_at = '2026-11-10T00:00:00Z'; }
            else { assert.deepEqual(body, { threshold: 2 }); threshold.value = 2; threshold.source = 'owner'; return route.fulfill({ json: { blocking_threshold: threshold } }); }
            return route.fulfill({ json: { member } });
        }
        if (request.method() !== 'GET' || ![showPath, '/council', '/lab/owner', '/council/members', memberPath].includes(url.pathname)) { errors.push('Unexpected fixture request: ' + request.method() + ' ' + url.pathname); return route.fulfill({ status: 404, body: 'Unexpected fixture request' }); }
        const fixturePage = fixture.page(url.pathname);
        const packet = { ...fixturePage, props: { ...fixturePage.props, errors: {} }, url: url.pathname, version: 'owned-council-ui', clearHistory: false, encryptHistory: false };
        if (request.headers()['x-inertia']) return route.fulfill({ json: packet, headers: { 'X-Inertia': 'true', Vary: 'X-Inertia' } });
        const encoded = JSON.stringify(packet).replaceAll('<', '\\u003c');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html class="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="${csrf}">${css}</head><body><div id="app"></div><script type="application/json" data-page="app">${encoded}</script><script type="module" src="/${entry.fileName}"></script></body></html>` });
    });
    // A visibility fixture exercises the published hook without changing its
    // timers or fetches. Even a host asking for 1ms must schedule a 15s floor.
    await page.addInitScript(() => {
        window.__councilHidden = false;
        window.__councilTimerDelays = [];
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => window.__councilHidden ? 'hidden' : 'visible' });
        const nativeTimeout = window.setTimeout.bind(window);
        window.setTimeout = (callback, delay, ...args) => {
            window.__councilTimerDelays.push(delay);
            return nativeTimeout(callback, delay, ...args);
        };
    });
    await page.goto('https://lab.test' + showPath);
    await page.locator('#council-approve-initiation').waitFor();
    await readable(page.locator('[data-surface="council-show"] h1'));
    assert.equal(await page.locator('img[src="x"]').count(), 0);
    const refused = await page.evaluate(() => ({ result: window.__councilDom.click('council-approve-initiation'), expected: window.__councilFence }));
    assert.deepEqual(refused.result, { ok: false, error: refused.expected });
    assert.equal(posts, 0);
    groups.push('actual-package-page-is-case-correct-text-only-and-agent-refused');
    await page.evaluate(() => { window.__councilHidden = true; document.dispatchEvent(new Event('visibilitychange')); });
    await page.waitForTimeout(16050);
    assert.equal(traffic.filter(row => row.method === 'GET' && row.path === detailPath).length, 0);
    assert(await page.evaluate(() => window.__councilTimerDelays.includes(15000)));
    const resumed = page.waitForResponse(response => new URL(response.url()).pathname === detailPath);
    await page.evaluate(() => { window.__councilHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal((await resumed).status(), 200);
    assert.equal(traffic.filter(row => row.method === 'GET' && row.path === detailPath).length, 1);
    groups.push('published-poll-keeps-15s-floor-and-pauses-during-synthetic-hidden-visibility');
    await page.locator('#council-approve-initiation').click();
    await page.locator('#council-owner-confirm').waitFor();
    for (const handle of ['council-owner-reason', 'council-owner-cancel', 'council-owner-confirm']) {
        const result = await page.evaluate(handle => ({ result: window.__councilDom.click(handle), expected: window.__councilFence }), handle);
        assert.deepEqual(result.result, { ok: false, error: result.expected });
    }
    assert.equal(await page.locator('html').getAttribute('data-agent-off-limits'), 'consent');
    assert.equal(await page.locator('#council-owner-confirm').evaluate(element => element.closest('section[aria-label="Human council decisions"]') === null), true);
    assert.equal(await page.locator('#council-owner-confirm').evaluate(element => element.closest('[data-agent-off-limits]') === document.documentElement), true);
    assert.equal(posts, 0);
    groups.push('actual-portaled-owner-controls-have-document-consent-and-no-agent-post');
    await page.screenshot({ path: path.join(directory, 'council-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(directory, 'council-mobile.png'), fullPage: true });
    const layout = await page.evaluate(() => ({
        viewport: innerWidth, documentWidth: document.documentElement.scrollWidth,
        overflow: Array.from(document.querySelectorAll('body *')).map(element => {
            const rect = element.getBoundingClientRect(), style = getComputedStyle(element);
            return { tag: element.tagName, id: element.id, class: element.className?.toString(), left: rect.left, right: rect.right, width: rect.width, display: style.display, minWidth: style.minWidth, whiteSpace: style.whiteSpace };
        }).filter(row => row.width > 0 && (row.right > innerWidth + 1 || row.left < -1)).slice(0, 30),
    }));
    fs.writeFileSync(path.join(directory, 'mobile-layout.json'), JSON.stringify(layout, null, 2) + '\n');
    // Keep this observation for the final assertion, after the independent
    // consent/CSRF/navigation checks have had a chance to run.
    await page.locator('#council-owner-reason').fill('Synthetic human note.');
    await page.evaluate(() => { document.querySelector('meta[name="csrf-token"]').content = 'expired-fixture-token'; });
    await page.locator('#council-owner-confirm').click();
    await page.getByText('Refused: csrf_expired', { exact: true }).waitFor();
    assert.equal(posts, 1); assert.equal(accepted, 0);
    await page.evaluate(csrf => { document.querySelector('meta[name="csrf-token"]').content = csrf; }, csrf);
    await page.locator('#council-owner-confirm').click();
    await page.getByTestId('change-state').filter({ hasText: 'round1 open' }).waitFor();
    assert.equal(posts, 2); assert.equal(accepted, 1);
    groups.push('human-ui-preserves-csrf-refusal-and-submits-native-structured-note');
    // Exercise every tab and long audit entry at the actual narrow viewport.
    for (const tab of ['overview', 'rounds', 'evidence', 'silence', 'audit']) {
        await page.locator('#council-tab-' + tab).click();
        if (tab === 'audit') {
            const auditTitle = page.getByText('synthetic.' + 'x'.repeat(180) + ' - owner:123', { exact: true });
            await auditTitle.scrollIntoViewIfNeeded();
            await page.waitForFunction(() => {
                const item = document.querySelector('[data-react-fancy-timeline-item]');
                return item && Number(getComputedStyle(item).opacity) >= 0.99;
            });
        }
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), tab+' must remain inside the mobile document');
    }
    await page.screenshot({ path: path.join(directory, 'council-mobile-audit.png'), fullPage: true });
    groups.push('all-five-native-tabs-and-long-audit-text-fit-390px');

    await page.evaluate(() => window.__councilRouter.visit('/council/members'));
    await page.locator('[data-surface="council-members"]').waitFor();
    await page.locator('[data-surface="council-show"]').waitFor({ state: 'detached' });
    const thresholdRefusal = await page.evaluate(() => ({ result: window.__councilDom.click('council-save-threshold'), expected: window.__councilFence }));
    assert.deepEqual(thresholdRefusal.result, { ok: false, error: thresholdRefusal.expected });
    await page.locator('#council-blocking-threshold').fill('2');
    await page.locator('#council-save-threshold').click();
    await page.getByText('Blocking threshold set to 2.', { exact: true }).waitFor();
    await readable(page.locator('[data-surface="council-members"] h1'));
    await readable(page.locator('[data-member="member@synthetic"] td').nth(1));
    await page.evaluate(() => document.documentElement.classList.remove('dark'));
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running' || animation.effect?.getTiming().iterations === Infinity));
    await readable(page.locator('[data-surface="council-members"] h1'));
    await readable(page.locator('[data-member="member@synthetic"] td').nth(1));
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running' || animation.effect?.getTiming().iterations === Infinity));
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Native member table must scroll within the mobile page');
    await page.screenshot({ path: path.join(directory, 'council-mobile-members.png'), fullPage: true });

    await page.evaluate(memberPath => window.__councilRouter.visit(memberPath), memberPath);
    await page.locator('[data-surface="council-member"]').waitFor();
    await page.locator('[data-surface="council-members"]').waitFor({ state: 'detached' });
    assert.equal(await page.locator('a[href^="javascript:"]').count(), 0);
    assert.equal(await page.locator('img[src="x"]').count(), 0);
    for (const handle of ['council-save-weight', 'council-save-project', 'council-remove-member']) {
        const refusal = await page.evaluate(handle => ({ result: window.__councilDom.click(handle), expected: window.__councilFence }), handle);
        assert.deepEqual(refusal.result, { ok: false, error: refusal.expected });
    }
    await page.locator('#council-member-weight').fill('0');
    await page.locator('#council-save-weight').click();
    await page.getByText('Weight for member@synthetic set to 0.', { exact: true }).waitFor();
    await page.locator('#council-project-name').fill('Reviewed synthetic project');
    await page.locator('#council-project-description').fill('Synthetic owner description.');
    await page.locator('#council-project-url').fill('https://fixture.invalid/project');
    await page.locator('#council-save-project').click();
    await page.getByText('Project for member@synthetic saved.', { exact: true }).waitFor();
    const link = page.locator('#council-member-url');
    assert.equal(await link.getAttribute('href'), 'https://fixture.invalid/project');
    assert((await link.getAttribute('rel')).includes('noopener'));
    await page.locator('#council-remove-member').click();
    for (const handle of ['council-remove-reason', 'council-confirm-remove', 'council-cancel-remove']) {
        const refusal = await page.evaluate(handle => ({ result: window.__councilDom.click(handle), expected: window.__councilFence }), handle);
        assert.deepEqual(refusal.result, { ok: false, error: refusal.expected });
    }
    await page.locator('#council-remove-reason').fill('Synthetic owner removal.');
    await page.locator('#council-confirm-remove').click();
    await page.getByTestId('member-removed-note').waitFor();
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Native member record and owner form must fit mobile');
    await page.screenshot({ path: path.join(directory, 'council-mobile-member.png'), fullPage: true });
    groups.push('native-members-and-member-controls-are-human-only-structured-and-mobile-safe');
    await page.evaluate(() => window.__councilRouter.visit('/council'));
    await page.locator('[data-surface="council-index"]').waitFor();
    assert.equal(await page.locator('html').getAttribute('data-agent-off-limits'), 'consent');
    const indexRefusal = await page.evaluate(() => ({ result: window.__councilDom.click('council-filter-package'), expected: window.__councilFence }));
    assert.deepEqual(indexRefusal.result, { ok: false, error: indexRefusal.expected });
    await page.locator('[data-surface="council-member"]').waitFor({ state: 'detached' });
    groups.push('council-to-council-transition-retains-agent-consent');
    await page.evaluate(() => window.__councilRouter.visit('/lab/owner'));
    await page.getByRole('heading', { name: 'Lab owner', exact: true }).waitFor();
    // Fancy keeps the outgoing page mounted until its exit animation ends.
    // Consent must cover that page until it is actually detached.
    await page.locator('section[aria-label="Human council decisions"]').waitFor({ state: 'detached' });
    assert.equal(await page.locator('html').getAttribute('data-agent-off-limits'), null);
    const control = await page.evaluate(() => { const button = document.createElement('button'); button.id = 'synthetic-safe-control'; button.onclick = () => { window.__safeClicked = true; }; document.body.append(button); return window.__councilDom.click(button.id); });
    assert.equal(control.ok, true); assert.equal(await page.evaluate(() => window.__safeClicked), true);
    groups.push('inertia-navigation-cleans-document-fence-and-positive-dom-control-still-works');
    await page.evaluate(showPath => { document.documentElement.setAttribute('data-agent-off-limits', 'auth'); window.__councilRouter.visit(showPath); }, showPath);
    await page.getByTestId('change-state').waitFor();
    assert.equal(await page.locator('html').getAttribute('data-agent-off-limits'), 'consent');
    await page.evaluate(() => window.__councilRouter.visit('/lab/owner'));
    await page.getByRole('heading', { name: 'Lab owner', exact: true }).waitFor();
    await page.locator('section[aria-label="Human council decisions"]').waitFor({ state: 'detached' });
    assert.equal(await page.locator('html').getAttribute('data-agent-off-limits'), 'auth');
    groups.push('preexisting-document-auth-fence-is-restored-after-council-navigation');
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(directory, 'controls.json'), JSON.stringify({ status: 'PASS_CONTROLS_MOBILE_ASSERTION_PENDING', groups, posts, accepted, browserErrors: errors, traffic, layout, actualAppAndUnmodifiedPackageFancyPages: true, syntheticVisibilityFixture: true, noMobilePassClaim: true }, null, 2) + '\n');
    assert(layout.documentWidth <= layout.viewport + 1, 'Native Council page must not overflow the mobile viewport');
    groups.push('desktop-and-mobile-package-modal-render-without-horizontal-overflow');
    const receipt = { status: 'PASS', groups, posts, accepted, browserErrors: errors, traffic, actualAppAndUnmodifiedPackageFancyPages: true, actualInstalledDomNavigation: true, serverAuthAndCsrfProvedSeparatelyInPhp: true, allNetworkInterceptedOfflineNoServiceDatabaseProviderCredentialOrRealNotice: true };
    fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
    console.log(JSON.stringify(receipt));
} catch (error) {
    fs.writeFileSync(path.join(directory, 'failure.json'), JSON.stringify({ message: error.message, errors, groups, traffic }, null, 2) + '\n');
    if (page) {
        fs.writeFileSync(path.join(directory, 'failure.html'), await page.content());
        await page.screenshot({ path: path.join(directory, 'failure.png'), fullPage: true });
    }
    throw error;
} finally { await page?.close(); await browser.close(); }
