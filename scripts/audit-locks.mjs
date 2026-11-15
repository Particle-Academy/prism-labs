import { createHash } from 'node:crypto';
import { appendFileSync, lstatSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { dirname, isAbsolute, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { remotionFixStatus } from './remotion-exception-guard.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const timeout = 180_000;

function git(base, args) {
    const result = spawnSync('git', args, { cwd: base, encoding: 'utf8', windowsHide: true, timeout: 30_000, maxBuffer: 4 * 1024 * 1024 });
    if (result.error || result.status !== 0) throw new Error('Cannot enumerate committed lockfiles from Git.');
    return result.stdout;
}

export function committedLocks(base) {
    const realRoot = realpathSync(base);
    const locks = git(base, ['ls-files', '-z']).split('\0').filter(path => /(?:^|\/)(?:package-lock\.json|composer\.lock)$/.test(path));
    if (locks.length === 0) throw new Error('No committed lockfiles found; refusing an empty audit.');
    return locks.sort().map(path => {
        if (isAbsolute(path) || path.split(/[\\/]/).some(part => part === '..') || /[\x00-\x1f\x7f]/.test(path)) throw new Error('Unsafe committed lockfile path.');
        const absolute = resolve(base, path);
        const inside = relative(realRoot, realpathSync(absolute));
        if (inside === '..' || inside.startsWith('..' + sep) || isAbsolute(inside) || !lstatSync(absolute).isFile()) throw new Error('Lockfile must be a regular file inside this repository.');
        const bytes = readFileSync(absolute);
        JSON.parse(bytes.toString('utf8')); // A corrupt lock is never an empty successful audit.
        return { path, ecosystem: path.endsWith('composer.lock') ? 'composer' : 'npm', sha256: createHash('sha256').update(bytes).digest('hex') };
    });
}

function runAudit(base, lock) {
    const cwd = dirname(resolve(base, lock.path));
    const options = { cwd, encoding: 'utf8', windowsHide: true, timeout, maxBuffer: 4 * 1024 * 1024 };
    if (lock.ecosystem === 'npm') {
        // npm run supplies its own installed CLI path, including on Windows.
        const cli = process.env.npm_execpath;
        if (!cli || !isAbsolute(cli)) return { error: new Error('Run through npm run audit:locks so the npm CLI is identified.'), status: null };
        return spawnSync(process.execPath, [cli, 'audit', '--package-lock-only', '--ignore-scripts', '--include=dev', '--include=optional', '--include=peer', '--audit-level=low', '--json'], options);
    }
    if (process.env.PRISM_AUDIT_COMPOSER_PHAR) {
        if (!isAbsolute(process.env.PRISM_AUDIT_COMPOSER_PHAR)) return { error: new Error('Composer PHAR path must be absolute.'), status: null };
        return spawnSync(process.env.PRISM_AUDIT_PHP_BINARY || 'php', [process.env.PRISM_AUDIT_COMPOSER_PHAR, 'audit', '--locked', '--no-interaction', '--format=json'], options);
    }
    // The Windows shell command is constant: no lock path or repository content
    // is interpolated. Its cwd is passed separately as data.
    return process.platform === 'win32'
        ? spawnSync(process.env.ComSpec || 'cmd.exe', ['/d', '/s', '/c', 'composer audit --locked --no-interaction --format=json'], options)
        : spawnSync('composer', ['audit', '--locked', '--no-interaction', '--format=json'], options);
}

export function npmAdvisories(body) {
    const entries = body.vulnerabilities;
    const counts = body.metadata?.vulnerabilities;
    if (body.error || !entries || typeof entries !== 'object' || Array.isArray(entries) || !counts || ['info', 'low', 'moderate', 'high', 'critical', 'total'].some(key => !Number.isSafeInteger(counts[key]) || counts[key] < 0) || Object.keys(entries).length !== counts.total || counts.total > 10_000) throw new Error('Incomplete npm audit response.');
    const severities = ['info', 'low', 'moderate', 'high', 'critical'];
    if (severities.reduce((sum, key) => sum + counts[key], 0) !== counts.total || severities.some(key => Object.values(entries).filter(entry => entry.severity === key).length !== counts[key])) throw new Error('Contradictory npm advisory counts.');
    const resolved = new Map();
    function visit(name, active = new Set()) {
        if (resolved.has(name)) return resolved.get(name);
        const entry = entries[name];
        if (!entry || entry.name !== name || !Array.isArray(entry.via) || entry.via.length === 0 || active.has(name) || active.size > 256) throw new Error('Incomplete npm advisory dependency graph.');
        const next = new Set([...active, name]);
        const advisories = entry.via.flatMap(via => {
            if (typeof via === 'string') return visit(via, next);
            const id = /^https:\/\/github\.com\/advisories\/(GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})$/.exec(via?.url ?? '')?.[1];
            if (!id || typeof via.name !== 'string' || !via.name || typeof via.title !== 'string' || !['info', 'low', 'moderate', 'high', 'critical'].includes(via.severity)) throw new Error('Incomplete underlying npm advisory.');
            return [{ id, package: via.name, severity: via.severity }];
        });
        const unique = [...new Map(advisories.map(advisory => [advisory.id + ':' + advisory.package, advisory])).values()];
        resolved.set(name, unique);
        return unique;
    }
    const all = Object.keys(entries).flatMap(name => visit(name));
    return [...new Map(all.map(advisory => [advisory.id + ':' + advisory.package, advisory])).values()];
}

function validateExceptions(exceptions, locks, now) {
    if (!Array.isArray(exceptions)) throw new Error('Audit exceptions must be a reviewed array.');
    const seen = new Set();
    for (const exception of exceptions) {
        const key = exception.lock + ':' + exception.id + ':' + exception.package;
        if (!locks.some(lock => lock.path === exception.lock && lock.ecosystem === 'npm') || !/^GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/.test(exception.id ?? '') || !/^[a-z0-9@/_.-]+$/.test(exception.package ?? '') || typeof exception.reason !== 'string' || !exception.reason.trim() || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(exception.expiresAt ?? '') || !Number.isFinite(Date.parse(exception.expiresAt)) || seen.has(key)) throw new Error('Malformed, duplicated or out-of-scope audit exception.');
        if (Date.parse(exception.expiresAt) <= now) throw new Error('Audit exception expired; repair the dependency or obtain a fresh reviewed decision.');
        if (new Date(exception.expiresAt).toISOString().replace('.000Z', 'Z') !== exception.expiresAt) throw new Error('Malformed audit exception expiry.');
        seen.add(key);
        if (exception.id !== 'GHSA-mrhx-c7xc-m3w5' || exception.package !== 'zod' || exception.guard !== 'remotion-zod-fixed-parent' || exception.owner !== 'prism' || !/^\d{4}-\d{2}-\d{2}$/.test(exception.reviewOn ?? '')) throw new Error('Exception has no reviewed owner or registry invalidation rule.');
    }
}

export function auditLocks(base, run = runAudit, { exceptions = [], now = Date.now() } = {}) {
    const locks = committedLocks(base);
    validateExceptions(exceptions, locks, now);
    const used = new Set();
    const results = locks.map(lock => {
        let outcome;
        try {
            const execution = run(base, lock);
            if (createHash('sha256').update(readFileSync(resolve(base, lock.path))).digest('hex') !== lock.sha256) throw new Error('Lockfile changed during its audit.');
            if (execution.error || execution.signal || !Number.isInteger(execution.status)) throw new Error('Audit process unavailable, timed out or interrupted.');
            const body = JSON.parse(execution.stdout);
            if (lock.ecosystem === 'npm') {
                const advisories = npmAdvisories(body);
                const accepted = advisories.filter(advisory => exceptions.some(exception => {
                    if (exception.lock !== lock.path || exception.id !== advisory.id || exception.package !== advisory.package) return false;
                    used.add(exception);
                    return true;
                }));
                const blocking = advisories.filter(advisory => !accepted.includes(advisory));
                const statusValid = execution.status === 0 || (execution.status === 1 && advisories.length > 0);
                outcome = { verdict: statusValid && blocking.length === 0 ? 'pass' : 'fail', exit: execution.status, vulnerabilities: body.metadata.vulnerabilities, blocking, accepted: accepted.map(advisory => ({ ...advisory, exception: exceptions.find(exception => exception.lock === lock.path && exception.id === advisory.id && exception.package === advisory.package) })) };
            } else {
                if (!body.advisories || typeof body.advisories !== 'object' || !Array.isArray(body.abandoned)) throw new Error('Incomplete Composer audit response.');
                const advisories = Object.values(body.advisories).flat().length;
                outcome = { verdict: execution.status === 0 && advisories === 0 && body.abandoned.length === 0 ? 'pass' : 'fail', exit: execution.status, advisories, abandoned: body.abandoned.length };
            }
        } catch {
            outcome = { verdict: 'unchecked', reason: 'Audit did not return complete valid evidence; run the package manager audit in this lockfile directory to diagnose.' };
        }
        return { ...lock, ...outcome };
    });
    const staleExceptions = exceptions.filter(exception => !used.has(exception));
    return { schema: 1, head: git(base, ['rev-parse', 'HEAD']).trim(), locks: results, checked: results.filter(result => result.verdict !== 'unchecked').length, passed: results.filter(result => result.verdict === 'pass').length, staleExceptions, ok: staleExceptions.length === 0 && results.every(result => result.verdict === 'pass') };
}

export async function auditWithRegistry(base, run = runAudit, { exceptions = [], now = Date.now(), fetchMetadata } = {}) {
    const report = auditLocks(base, run, { exceptions, now });
    if (exceptions.length) {
        try {
            report.remotionRegistry = await remotionFixStatus(fetchMetadata);
            if (report.remotionRegistry.fixAvailable) {
                report.ok = false;
                report.exceptionInvalid = 'A published parent can resolve patched Zod >=4.6.6; upgrade and remove the exception.';
            }
        } catch {
            report.ok = false;
            report.exceptionInvalid = 'Registry guard is UNCHECKED or changed shape; re-review the exception.';
        }
    }
    return report;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const policy = resolve(root, 'scripts/lock-audit-exceptions.json');
        const exceptions = JSON.parse(readFileSync(policy, 'utf8'));
        const evidence = await auditWithRegistry(root, runAudit, { exceptions });
        writeFileSync(resolve(root, 'lock-audit-evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
        console.log(JSON.stringify(evidence, null, 2));
        if (process.env.GITHUB_STEP_SUMMARY) {
            const accepted = evidence.locks.flatMap(lock => (lock.accepted ?? []).map(item => `- Temporary accepted advisory ${item.id} (${item.package}) in ${lock.path.replaceAll('|', '\\|')}; owner ${item.exception.owner}, re-review ${item.exception.reviewOn}, expires ${item.exception.expiresAt}.`));
            appendFileSync(process.env.GITHUB_STEP_SUMMARY, `\nCommitted lock audit: ${evidence.checked}/${evidence.locks.length} checked; ${evidence.ok ? 'passed' : 'FAILED'}.\n\n${accepted.join('\n')}\n${evidence.exceptionInvalid ?? ''}\n`);
        }
        if (!evidence.ok) process.exitCode = 1;
    } catch (error) {
        console.error('Committed-lock audit refused:', error.message);
        process.exitCode = 1;
    }
}
