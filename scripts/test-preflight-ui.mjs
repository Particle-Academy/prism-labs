import assert from 'node:assert/strict';
import { build } from 'vite';
import { resolve } from 'node:path';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import tailwindcss from '@tailwindcss/vite';

// The actual entry point, Home, Inertia context and Fancy components. All
// requests are intercepted; no application server, provider or DB is reached.
const result = await build({ configFile: false, logLevel: 'error', plugins: [tailwindcss()], build: {
    write: false, minify: false, rollupOptions: { input: [resolve('resources/css/app.css'), resolve('resources/js/app.tsx')] },
} });
const output = (Array.isArray(result) ? result[0] : result).output;
const entry = output.find(item => item.type === 'chunk' && item.isEntry);
assert(entry);
const files = new Map(output.map(item => [`/${item.fileName}`, item.type === 'chunk' ? item.code : item.source]));
const css = output.filter(item => item.fileName.endsWith('.css')).map(item => `<link rel="stylesheet" href="/${item.fileName}">`).join('');
const artifactDir = resolve('storage/framework/testing/preflight-browser');
await mkdir(artifactDir, { recursive: true });
const browser = await chromium.launch({ headless: true });
const failures = [], requests = [];
let page;
const status = ready => ({ ready, status: ready ? 'ready' : 'unavailable', message: ready ? 'Verified fixture prerequisite.' : '<img src=x onerror="window.preflightInjected=true"> unavailable fixture' });
const snapshot = ready => ({ ready, checked_at: '2026-11-07T20:00:00+00:00',
    notice: 'Readiness is not approval to launch. Provider key presence does not verify live access.', checks: {
        workers: { ...status(ready), queues: [] }, database: status(ready),
        providers: { ...status(ready), providers: [
            { key: 'anthropic', label: 'Anthropic', presence: ready ? 'present' : 'missing', required: true },
            { key: 'openai', label: 'OpenAI', presence: 'missing', required: false },
        ] },
        core_actions: { ...status(ready), head: ready ? 'a'.repeat(40) : null, checked_at: ready ? 1794081600 : null },
    } });
let ready = false;
try {
    page = await browser.newPage();
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => failures.push(error.message));
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        requests.push({ path: url.pathname, method: request.method() });
        if (url.origin !== 'https://lab.test' || request.method() !== 'GET') {
            failures.push('Unexpected nonlocal or mutating browser request.');
            return route.abort('blockedbyclient');
        }
        if (files.has(url.pathname)) return route.fulfill({ contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript', body: files.get(url.pathname) });
        if (url.pathname !== '/lab') {
            // Shared shell's free presence poll may run; no paid request can.
            if (url.pathname === '/lab/team/readiness') return route.fulfill({ json: { lanes: [] } });
            failures.push('Unexpected browser request: ' + url.pathname);
            return route.abort('blockedbyclient');
        }
        const props = { preflight: snapshot(ready), dataAvailable: ready,
            metrics: ready ? { activeRuns: 0, dailyTokens: 0, dailyCost: 0, operations: 0 } : null,
            benchmarks: [], consensus: [], recent: [], errors: {} };
        const inertia = JSON.stringify({ component: 'Lab/Cockpit', props, url: '/lab', version: 'preflight-fixture', clearHistory: false, encryptHistory: false }).replaceAll('<', String.fromCharCode(92) + 'u003c');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html class="dark" style="color-scheme:dark"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="fixture-only">${css}</head><body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased"><div id="app"></div><script type="application/json" data-page="app">${inertia}</script><script type="module" src="/${entry.fileName}"></script></body></html>` });
    });
    for (const width of [1280, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const positive of [false, true]) {
            ready = positive;
            await page.goto('https://lab.test/lab');
            const panel = page.getByTestId('session-readiness');
            await panel.waitFor({ state: 'visible' });
            assert.match(await panel.innerText(), positive ? /Ready for approval/ : /Setup required/);
            assert.equal(await panel.locator('[data-check]').count(), 4);
            assert.match(await panel.innerText(), /Readiness is not approval to launch/);
            assert.match(await panel.innerText(), positive ? /Anthropic \(coordinator\): PRESENT/ : /Anthropic \(coordinator\): MISSING/);
            assert.match(await panel.innerText(), /OpenAI: MISSING/);
            assert.equal(await page.evaluate(() => window.preflightInjected), undefined);
            assert.equal(await panel.locator('img, script').count(), 0);
            assert.equal(await panel.getByRole('button').count(), 0);
            assert.equal(await panel.getByRole('link', { name: 'Refresh status as JSON' }).getAttribute('href'), '/lab/preflight');
            const body = await page.locator('body').innerText();
            assert(!body.includes('The Lab is running.'));
            assert.match(body, positive ? /No workflows yet/ : /Workflow data is unavailable/);
            assert.equal(await page.locator('.lab-metric').count(), 4);
            assert.equal(await page.locator('.lab-metrics').evaluate(el => getComputedStyle(el).display), 'grid', 'Use the actual compiled application stylesheet.');
            if (!positive) assert.equal(await page.locator('.lab-metric').filter({ hasText: 'Unavailable' }).count(), 4);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Home must fit the viewport.');
            await page.screenshot({ path: resolve(artifactDir, `${positive ? 'ready' : 'blocked'}-${width}.png`), fullPage: true });
        }
    }
    assert.deepEqual(failures, []);
    await writeFile(resolve(artifactDir, 'report.json'), JSON.stringify({ status: 'PASS', groups: ['actual-home', 'four-prerequisites', 'provider-presence', 'unavailable-metrics', 'escaped-errors', 'no-launch-control', 'no-paid-request', 'two-viewports'], widths: [1280, 390], requests, failures }, null, 2) + '\n');
    console.log('Session preflight: actual Home/Fancy/escaping/unavailable/positive controls passed at two viewports.');
} catch (error) {
    if (page) {
        await page.screenshot({ path: resolve(artifactDir, 'failure.png'), fullPage: true });
        await writeFile(resolve(artifactDir, 'failure.html'), await page.content());
    }
    await writeFile(resolve(artifactDir, 'failure.json'), JSON.stringify({ error: error.message, failures, requests }, null, 2) + '\n');
    console.error(JSON.stringify({ error: error.message, browserErrors: failures }));
    throw error;
} finally {
    await browser.close();
}
