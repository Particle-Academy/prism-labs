import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { siblingRepositories, checkNpmCurrency, checkComposerCurrency } from './dogfood-dependencies.mjs';

test('every manifest path is a checkout, including ACP and future satellites', () => {
    const manifest = JSON.parse(readFileSync(new URL('../composer.json', import.meta.url), 'utf8'));
    if (!manifest.require['particle-academy/prism-acp']) {
        manifest.repositories.push({ type: 'path', url: '../prism-acp' });
        manifest.require['particle-academy/prism-acp'] = 'dev-main';
    }
    assert.ok(siblingRepositories(manifest).includes('prism-acp'));
    manifest.repositories.push({ type: 'path', url: '../prism-new-satellite' });
    manifest.require['particle-academy/prism-new-satellite'] = 'dev-main';
    assert.ok(siblingRepositories(manifest).includes('prism-new-satellite'));
});

test('unapproved paths, duplicate paths and empty inventories fail closed', () => {
    for (const url of ['../other', '../../prism', '../prism/../../secret', '../prism;whoami', 'https://example.test/prism', '../PRISM']) {
        assert.throws(() => siblingRepositories({ repositories: [{ type: 'path', url }], require: {} }));
    }
    assert.throws(() => siblingRepositories({ repositories: [] }));
    assert.throws(() => siblingRepositories({ repositories: [{ type: 'path', url: '../prism' }], require: {} }));
    assert.throws(() => siblingRepositories({ repositories: [{ type: 'path', url: '../prism' }, { type: 'path', url: '../prism' }], require: { 'particle-academy/prism': 'dev-main' } }));
});

test('currency distinguishes current, stale, missing and failed registry evidence', async () => {
    const manifest = { dependencies: { '@particle-academy/example': '^1.0.0' } };
    const lock = { packages: { 'node_modules/@particle-academy/example': { version: '1.0.0' } } };
    const metadata = version => async (_url, options) => {
        assert.equal(options.headers['Cache-Control'], 'no-cache');
        return { ok: true, json: async () => ({ version }) };
    };
    assert.equal((await checkNpmCurrency(manifest, lock, metadata('1.0.0')))[0].current, true);
    assert.equal((await checkNpmCurrency(manifest, lock, metadata('2.0.0')))[0].current, false);
    assert.equal((await checkNpmCurrency(manifest, {}, metadata('1.0.0')))[0].current, false);
    await assert.rejects(checkNpmCurrency(manifest, lock, async () => ({ ok: false, status: 503 })));
    await assert.rejects(checkNpmCurrency(manifest, lock, metadata(undefined)));
    await assert.rejects(checkNpmCurrency({}, {}, metadata('1.0.0')));
});

test('Composer release currency excludes path aliases and compares stable registry versions', async () => {
    const manifest = { repositories: [{ type: 'path', url: '../prism' }], require: { 'particle-academy/prism': 'dev-main as 0.130.0', 'particle-academy/fancy-flow-php': '0.63.0' } };
    const lock = { packages: [{ name: 'particle-academy/fancy-flow-php', version: 'v0.64.0' }] };
    const metadata = async (url, options) => {
        assert.equal(url, 'https://repo.packagist.org/p2/particle-academy/fancy-flow-php.json');
        assert.equal(options.headers['Cache-Control'], 'no-cache');
        return { ok: true, json: async () => ({ packages: { 'particle-academy/fancy-flow-php': [{ version: 'dev-main' }, { version: 'v0.9.0' }, { version: 'v0.64.0' }] } }) };
    };
    assert.equal((await checkComposerCurrency(manifest, lock, metadata))[0].current, true);
    lock.packages[0].version = 'v0.63.0';
    assert.equal((await checkComposerCurrency(manifest, lock, metadata))[0].current, false);
    await assert.rejects(checkComposerCurrency(manifest, lock, async () => ({ ok: false, status: 503 })));
    await assert.rejects(checkComposerCurrency(manifest, lock, async () => ({ ok: true, json: async () => ({}) })));
});
