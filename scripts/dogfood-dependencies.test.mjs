import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { siblingRepositories, checkNpmCurrency, checkComposerCurrency, checkComposerAliasCurrency } from './dogfood-dependencies.mjs';

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

const aliasManifest = alias => ({ repositories: [{ type: 'path', url: '../prism' }, { type: 'path', url: '../prism-acp' }], require: { 'particle-academy/prism': alias, 'particle-academy/prism-acp': 'dev-main' } });
const aliasMetadata = versions => async (url, options) => {
    assert.equal(url, 'https://repo.packagist.org/p2/particle-academy/prism.json');
    assert.equal(options.headers['Cache-Control'], 'no-cache');
    assert.equal(options.headers.Pragma, 'no-cache');
    assert.equal(options.cache, 'no-store');
    return { ok: true, json: async () => ({ packages: { 'particle-academy/prism': versions.map(version => ({ version })) } }) };
};

test('path aliases check stable currency independently of their dev-main source lock', async () => {
    const metadata = aliasMetadata(['dev-main', '0.144.0-beta.1', 'v0.9.0', 'v0.143.0', '0.142.0']);
    assert.deepEqual(await checkComposerAliasCurrency(aliasManifest('dev-main as 0.143.0'), metadata), [
        { name: 'particle-academy/prism', alias: '0.143.0', latest: '0.143.0', current: true },
    ]);
    assert.equal((await checkComposerAliasCurrency(aliasManifest('dev-main as 0.135.0'), metadata))[0].current, false);
    assert.equal((await checkComposerAliasCurrency(aliasManifest('dev-main as 0.144.0'), metadata))[0].current, true);
});

test('alias currency fails closed on malformed labels, empty inventory and failed or missing registry evidence', async () => {
    for (const alias of ['dev-main as ^0.143', 'dev-main as 0.143.0-beta.1', 'dev-main as latest', '^0.143', 'dev-main as 0.143.0 || *', undefined, null, ['dev-main as 0.143.0'], {}, 42]) {
        await assert.rejects(checkComposerAliasCurrency(aliasManifest(alias), aliasMetadata(['0.143.0'])));
    }
    await assert.rejects(checkComposerAliasCurrency(aliasManifest('dev-main'), aliasMetadata(['0.143.0'])));
    await assert.rejects(checkComposerAliasCurrency(aliasManifest('dev-main as 0.143.0'), async () => ({ ok: false, status: 503 })));
    await assert.rejects(checkComposerAliasCurrency(aliasManifest('dev-main as 0.143.0'), aliasMetadata(['dev-main', '0.144.0-alpha.1'])));
    await assert.rejects(checkComposerAliasCurrency(aliasManifest('dev-main as 0.143.0'), async () => ({ ok: true, json: async () => ({}) })));
});

test('the shared stable selector orders numeric versions without integer precision loss', async () => {
    const metadata = aliasMetadata(['9007199254740993.0.0', '9007199254740992.99.99', '2.0.0']);
    assert.equal((await checkComposerAliasCurrency(aliasManifest('dev-main as 9007199254740992.99.99'), metadata))[0].current, false);
});

test('all actual manifest aliases are checked while the noncanonical exact ACP prerelease stays separate', async () => {
    const manifest = JSON.parse(readFileSync(new URL('../composer.json', import.meta.url), 'utf8'));
    const declared = Object.entries(manifest.require).filter(([, value]) => /^dev-main as \d+\.\d+\.\d+$/.test(value));
    assert.ok(declared.length > 0);
    const called = [];
    const results = await checkComposerAliasCurrency(manifest, async url => {
        const name = new URL(url).pathname.slice('/p2/'.length, -'.json'.length);
        called.push(name);
        const alias = manifest.require[name].match(/^dev-main as (\d+\.\d+\.\d+)$/)?.[1];
        assert.ok(alias, 'Only explicit release aliases belong in this guard.');
        return { ok: true, json: async () => ({ packages: { [name]: [{ version: alias }] } }) };
    });
    assert.deepEqual(called.sort(), declared.map(([name]) => name).sort());
    assert.equal(results.length, declared.length);
    assert.ok(results.every(result => result.current));
});
