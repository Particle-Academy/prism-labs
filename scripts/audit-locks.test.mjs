import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { auditLocks, auditWithRegistry, committedLocks } from './audit-locks.mjs';

function removeOwnedFixture(root) {
    assert.equal(dirname(realpathSync(root)), realpathSync(tmpdir()), 'fixture cleanup stays inside its explicit temporary parent');
    rmSync(root, { recursive: true, force: true });
}

function repository(files, use) {
    const root = mkdtempSync(join(tmpdir(), 'prism-lock-audit-'));
    try {
        execFileSync('git', ['init', '--quiet', root]);
        for (const file of files) {
            mkdirSync(dirname(join(root, file)), { recursive: true });
            writeFileSync(join(root, file), '{}');
        }
        if (files.length) execFileSync('git', ['-C', root, 'add', '--', ...files]);
        execFileSync('git', ['-C', root, '-c', 'user.name=Lock audit fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'Owned lock audit fixture']);
        return use(root);
    } finally {
        removeOwnedFixture(root);
    }
}
const advisory = { name: 'zod', title: 'Owned advisory fixture', severity: 'high', url: 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5' };
const npm = (high = 0, status = high ? 1 : 0, via = [advisory]) => ({ status, stdout: JSON.stringify({ vulnerabilities: high ? { zod: { name: 'zod', severity: 'high', via } } : {}, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high, critical: 0, total: high } } }) });
const composer = { status: 0, stdout: JSON.stringify({ advisories: [], abandoned: [] }) };

test('discovers every tracked nested lock, including spaces; ignores untracked files', () => {
    repository(['composer.lock', 'package-lock.json', 'tools/render space/package-lock.json', 'tools/php/composer.lock'], root => {
        writeFileSync(join(root, 'untracked-package-lock.json'), '{}');
        assert.equal(committedLocks(root).length, 4);
        const called = [];
        const report = auditLocks(root, (_, lock) => { called.push(lock.path); return lock.ecosystem === 'npm' ? npm() : composer; });
        assert.deepEqual(called, ['composer.lock', 'package-lock.json', 'tools/php/composer.lock', 'tools/render space/package-lock.json']);
        assert.equal(report.ok, true);
        assert.equal(report.checked, 4);
        assert.equal(report.passed, 4);
        assert.ok(report.locks.every(lock => /^[0-9a-f]{64}$/.test(lock.sha256)));
    });
});

test('a failing nested audit fails the gate while later locks are still audited', () => {
    repository(['composer.lock', 'package-lock.json', 'tools/a/package-lock.json', 'tools/z/package-lock.json'], root => {
        const called = [];
        const report = auditLocks(root, (_, lock) => { called.push(lock.path); return lock.ecosystem === 'composer' ? composer : npm(lock.path.includes('/a/') ? 1 : 0); });
        assert.equal(report.ok, false);
        assert.equal(report.checked, 4);
        assert.equal(report.passed, 3);
        assert.equal(called.at(-1), 'tools/z/package-lock.json');
        assert.equal(report.locks.find(lock => lock.path.includes('/a/')).verdict, 'fail');
        const lyingStatus = auditLocks(root, () => npm(1, 0));
        assert.equal(lyingStatus.ok, false);
    });
});

test('missing tools, timeout, malformed JSON and incomplete evidence are UNCHECKED failures', () => {
    repository(['package-lock.json', 'tools/render/package-lock.json'], root => {
        for (const result of [{ error: Error('missing'), status: null }, { signal: 'SIGTERM', status: null }, { status: 0, stdout: 'invalid' }, { status: 0, stdout: '{}' }]) {
            let count = 0;
            const report = auditLocks(root, () => { count++; return result; });
            assert.equal(count, 2);
            assert.equal(report.ok, false);
            assert.equal(report.checked, 0);
            assert.ok(report.locks.every(lock => lock.verdict === 'unchecked'));
        }
    });
});

test('no locks and malformed committed locks are refusals, never a vacuous pass', () => {
    repository([], root => assert.throws(() => committedLocks(root), /empty audit/));
    repository(['package-lock.json'], root => {
        writeFileSync(join(root, 'package-lock.json'), '{invalid');
        assert.throws(() => auditLocks(root));
    });
});

const now = Date.parse('2026-10-27T08:00:00Z');
const exception = { lock: 'tools/render/package-lock.json', id: 'GHSA-mrhx-c7xc-m3w5', package: 'zod', owner: 'prism', reviewOn: '2026-11-03', expiresAt: '2026-11-10T08:00:00Z', guard: 'remotion-zod-fixed-parent', reason: 'Owned reviewed fixture' };
const metadata = fixed => async name => name === '@remotion/bundler'
    ? { version: '4.0.539', dependencies: { '@remotion/studio': '4.0.539' } }
    : { version: '4.0.539', dependencies: { zod: fixed ? '4.6.6' : '4.5.4' } };

test('the exact exception remains visible; a new advisory or another lock cannot use it', () => {
    repository(['package-lock.json', 'tools/render/package-lock.json'], root => {
        const run = (_, lock) => lock.path === exception.lock ? npm(1) : npm();
        const report = auditLocks(root, run, { exceptions: [exception], now });
        assert.equal(report.ok, true);
        assert.equal(report.locks[1].accepted[0].id, exception.id);
        const newAdvisory = { ...advisory, url: 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc', severity: 'low' };
        const additional = auditLocks(root, (_, lock) => lock.path === exception.lock ? npm(1, 1, [advisory, newAdvisory]) : npm(), { exceptions: [exception], now });
        assert.equal(additional.ok, false);
        assert.equal(additional.locks[1].blocking[0].id, 'GHSA-aaaa-bbbb-cccc');
        const wrongLock = auditLocks(root, run, { exceptions: [{ ...exception, lock: 'package-lock.json' }], now });
        assert.equal(wrongLock.ok, false);
        assert.equal(wrongLock.staleExceptions.length, 1);
        assert.throws(() => auditLocks(root, run, { exceptions: [exception], now: Date.parse(exception.expiresAt) }), /expired/);
        assert.throws(() => auditLocks(root, run, { exceptions: [{ ...exception, expiresAt: 'invalid' }], now }), /Malformed/);
        const stale = auditLocks(root, () => npm(), { exceptions: [exception], now });
        assert.equal(stale.ok, false);
    });
});

test('npm parent meta-advisories resolve to underlying IDs; unknown graphs refuse', () => {
    repository(['tools/render/package-lock.json'], root => {
        const body = JSON.parse(npm(1).stdout);
        body.vulnerabilities.parent = { name: 'parent', severity: 'high', via: ['zod'] };
        body.metadata.vulnerabilities.high = 2;
        body.metadata.vulnerabilities.total = 2;
        const report = auditLocks(root, () => ({ status: 1, stdout: JSON.stringify(body) }), { exceptions: [exception], now });
        assert.equal(report.ok, true);
        assert.equal(report.locks[0].accepted.length, 1);
        body.vulnerabilities.parent.via = ['missing'];
        const unknown = auditLocks(root, () => ({ status: 1, stdout: JSON.stringify(body) }), { exceptions: [exception], now });
        assert.equal(unknown.ok, false);
        assert.equal(unknown.locks[0].verdict, 'unchecked');
    });
});

test('a published fixed parent or unavailable registry invalidates the exception', async () => {
    // Keep the owned repository alive until the asynchronous registry check ends.
    const root = mkdtempSync(join(tmpdir(), 'prism-lock-registry-'));
    try {
        mkdirSync(join(root, 'tools/render'), { recursive: true });
        writeFileSync(join(root, exception.lock), '{}');
        execFileSync('git', ['init', '--quiet', root]);
        execFileSync('git', ['-C', root, 'add', '.']);
        execFileSync('git', ['-C', root, '-c', 'user.name=Lock audit fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'Owned registry fixture']);
        const valid = await auditWithRegistry(root, () => npm(1), { exceptions: [exception], now, fetchMetadata: metadata(false) });
        assert.equal(valid.ok, true);
        const fixed = await auditWithRegistry(root, () => npm(1), { exceptions: [exception], now, fetchMetadata: metadata(true) });
        assert.equal(fixed.ok, false);
        assert.equal(fixed.remotionRegistry.fixAvailable, true);
        const missing = await auditWithRegistry(root, () => npm(1), { exceptions: [exception], now, fetchMetadata: async () => { throw Error('offline'); } });
        assert.equal(missing.ok, false);
        assert.match(missing.exceptionInvalid, /UNCHECKED/);
    } finally {
        removeOwnedFixture(root);
    }
});

test('contradictory counts, registry errors and cyclic advisory graphs cannot hide behind an exception', () => {
    repository(['tools/render/package-lock.json'], root => {
        const errorBody = JSON.parse(npm(1).stdout); errorBody.error = { code: 'REGISTRY_UNAVAILABLE' };
        const wrongCounts = JSON.parse(npm(1).stdout); wrongCounts.metadata.vulnerabilities.high = 0;
        const cycle = JSON.parse(npm(1).stdout); cycle.vulnerabilities.zod.via = ['zod'];
        for (const body of [errorBody, wrongCounts, cycle]) {
            const report = auditLocks(root, () => ({ status: 1, stdout: JSON.stringify(body) }), { exceptions: [exception], now });
            assert.equal(report.ok, false);
            assert.equal(report.locks[0].verdict, 'unchecked');
        }
    });
});
