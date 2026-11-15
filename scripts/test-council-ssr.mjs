import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'vite';
import { chromium } from 'playwright';
import { createCouncilFixture } from './fixtures/council.mjs';

assert(!fs.existsSync('.env'), 'Use a credential-free checkout.');
const directory = path.resolve(process.argv[2]);
assert(!fs.existsSync(directory), 'Preserve the previous SSR evidence.');
fs.mkdirSync(directory, { recursive: true });
const temporary = fs.mkdtempSync(path.resolve('storage/framework/testing/council-ssr-'));
const entry = '\0council-ssr-entry', transport = '\0council-ssr-capture';
try {
    const built = await build({
        configFile: false, logLevel: 'error',
        plugins: [{
            name: 'council-native-ssr-without-listener', enforce: 'pre',
            resolveId(id) {
                if (id === 'council-ssr-entry') return entry;
                if (id === '@inertiajs/react/server') return transport;
            },
            load(id) {
                if (id === transport) return 'export default function capture(handler) { globalThis.__councilSsrHandler = handler; }';
                if (id === entry) return `import ${JSON.stringify(path.resolve('resources/js/ssr.tsx').replaceAll('\\', '/'))};\nexport const renderPage = packet => globalThis.__councilSsrHandler(packet);`;
            },
        }],
        // Keep the actual Fancy and Inertia renderer in the bundle. Only the
        // HTTP listener above is replaced; no server or service is started.
        ssr: { noExternal: ['@particle-academy/fancy-inertia', '@inertiajs/react'] },
        build: { ssr: true, write: false, rollupOptions: { input: 'council-ssr-entry', output: { entryFileNames: 'probe.mjs', chunkFileNames: '[name]-[hash].mjs' } } },
    });
    const output = (Array.isArray(built) ? built[0] : built).output;
    const code = output.filter(item => item.type === 'chunk').map(item => item.code).join('\n');
    assert(/__councilSsrHandler\s*=\s*handler/.test(code), 'Refuse execution unless the server listener was captured.');
    assert(!/['"]@inertiajs\/react\/server['"]/.test(code));
    assert(!/['"]@particle-academy\/fancy-inertia\/server['"]/.test(code));
    for (const item of output) {
        const target = path.resolve(temporary, item.fileName);
        assert(target.startsWith(temporary + path.sep), 'SSR output escaped its owned directory.');
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.writeFileSync(target, item.type === 'chunk' ? item.code : item.source, { flag: 'wx' });
    }
    const probe = await import(pathToFileURL(path.join(temporary, 'probe.mjs')).href);
    const fixture = createCouncilFixture();
    const fixturePage = fixture.page(fixture.showPath);
    const packet = { ...fixturePage, url: fixture.showPath, version: 'synthetic-ssr', props: { ...fixturePage.props, errors: {} } };
    const rendered = await probe.renderPage(packet);
    fs.writeFileSync(path.join(directory, 'council-show.html'), rendered.body);
    assert(rendered.body.includes('id="council-approve-initiation"'), 'Render the real owner page, not an empty placeholder.');
    assert(rendered.body.includes('data-agent-off-limits="consent"'), 'The actual SSR resolver must retain the Council consent boundary.');
    assert(rendered.body.includes('aria-label="Human council decisions"'));
    assert(!rendered.body.includes('<img src=x'), 'Untrusted titles remain text during SSR.');
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ offline: true, serviceWorkers: 'block' });
        await page.route('**/*', route => route.abort('blockedbyclient'));
        await page.setContent(rendered.body);
        assert.equal(await page.locator('#council-approve-initiation').evaluate(element => element.closest('section[aria-label="Human council decisions"]')?.getAttribute('data-agent-off-limits')), 'consent');
        assert.equal(await page.locator('img[src="x"]').count(), 0);
        for (const [route, handle] of [
            ['/council', 'council-filter-package'],
            ['/council/members', 'council-save-threshold'],
            [fixture.memberPath, 'council-save-weight'],
        ]) {
            const nativePage = fixture.page(route);
            const answer = await probe.renderPage({ ...nativePage, url: route, version: 'synthetic-ssr', props: { ...nativePage.props, errors: {} } });
            fs.writeFileSync(path.join(directory, nativePage.component.replace('/', '-') + '.html'), answer.body);
            await page.setContent(answer.body);
            assert.equal(await page.locator('#' + handle).evaluate(element => element.closest('section[aria-label="Human council decisions"]')?.getAttribute('data-agent-off-limits')), 'consent');
            assert.equal(await page.locator('a[href^="javascript:"]').count(), 0);
            assert.equal(await page.locator('img[src="x"]').count(), 0);
        }
    } finally { await browser.close(); }
    const receipt = { status: 'PASS', actualSsrEntry: true, actualFancyAndInertiaRenderers: true, onlyHttpListenerCaptured: true, actualParsedOwnerControlInsideConsent: true, ownerPageRenderedWithConsent: true, noServiceDatabaseProviderOrRealEnvironmentOperation: true };
    fs.writeFileSync(path.join(directory, 'final.json'), JSON.stringify(receipt, null, 2) + '\n');
    console.log(JSON.stringify(receipt));
} finally {
    assert.equal(path.dirname(fs.realpathSync(temporary)), fs.realpathSync('storage/framework/testing'));
    fs.rmSync(temporary, { recursive: true, force: true });
    delete globalThis.__councilSsrHandler;
}
