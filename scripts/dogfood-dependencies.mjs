import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export function siblingRepositories(manifest) {
    const repositories = (manifest.repositories ?? []).filter(repository => repository.type === 'path');
    if (repositories.length === 0) throw new Error('No path dependencies found; the dogfood inventory would be empty.');
    const names = repositories.map(repository => {
        if (!/^\.\.\/prism(?:-[a-z0-9]+)*$/.test(repository.url ?? '')) throw new Error(`Unsafe sibling path: ${repository.url}`);
        const name = basename(repository.url);
        if (!Object.hasOwn(manifest.require ?? {}, `particle-academy/${name}`)) throw new Error(`Path ${name} has no matching direct dependency.`);
        return name;
    });
    if (new Set(names).size !== names.length) throw new Error('Duplicate sibling paths.');
    return names;
}

function git(cwd, ...args) {
    return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true, timeout: 30000 }).trim();
}

function repositoryIdentity(cwd) {
    try {
        const remote = git(cwd, 'remote', 'get-url', 'origin');
        // Do not emit credentials embedded in a custom remote URL.
        const identity = remote.match(/^(?:https:\/\/github\.com\/|git@github\.com:)([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+?)(?:\.git)?$/);
        return identity?.[1] ?? null;
    } catch {
        return null;
    }
}

export function inventory(base, names) {
    const hash = file => createHash('sha256').update(readFileSync(resolve(base, file))).digest('hex');
    return {
        captured_at: new Date().toISOString(),
        resolution: process.env.DOGFOOD_RESOLUTION ?? 'local',
        lab_sha: git(base, 'rev-parse', 'HEAD'),
        lab_dirty: git(base, 'status', '--porcelain', '--untracked-files=no') !== '',
        composer_lock_sha256: hash('composer.lock'),
        npm_lock_sha256: hash('package-lock.json'),
        siblings: names.map(name => ({
            path: `../${name}`,
            repository: repositoryIdentity(resolve(base, '..', name)),
            sha: git(resolve(base, '..', name), 'rev-parse', 'HEAD'),
            dirty: git(resolve(base, '..', name), 'status', '--porcelain', '--untracked-files=no') !== '',
        })),
    };
}

export async function checkNpmCurrency(manifest, lock, fetchMetadata = fetch) {
    const names = Object.keys({ ...manifest.dependencies, ...manifest.devDependencies }).filter(name => name.startsWith('@particle-academy/'));
    if (names.length === 0) throw new Error('No first-party npm dependencies to check.');
    const results = await Promise.all(names.map(async name => {
        const response = await fetchMetadata(`https://registry.npmjs.org/${encodeURIComponent(name)}/latest`, {
            headers: { 'Cache-Control': 'no-cache', Accept: 'application/json' }, signal: AbortSignal.timeout(15000),
        });
        if (!response.ok) throw new Error(`Registry lookup failed for ${name}: ${response.status}`);
        const { version } = await response.json();
        if (typeof version !== 'string' || !/^\d+\.\d+\.\d+$/.test(version)) throw new Error(`Registry returned no stable version for ${name}.`);
        const installed = lock.packages?.[`node_modules/${name}`]?.version;
        return { name, installed: installed ?? null, latest: version, current: installed === version };
    }));
    return results;
}

export async function checkComposerCurrency(manifest, lock, fetchMetadata = fetch) {
    const paths = new Set(siblingRepositories(manifest).map(name => `particle-academy/${name}`));
    const names = Object.keys({ ...manifest.require, ...manifest['require-dev'] })
        .filter(name => name.startsWith('particle-academy/') && !paths.has(name));
    return Promise.all(names.map(async name => {
        const response = await fetchMetadata(`https://repo.packagist.org/p2/${name}.json`, {
            headers: { 'Cache-Control': 'no-cache', Accept: 'application/json' }, signal: AbortSignal.timeout(15000),
        });
        if (!response.ok) throw new Error(`Packagist lookup failed for ${name}: ${response.status}`);
        const body = await response.json();
        const stable = (body.packages?.[name] ?? []).map(item => item.version?.replace(/^v/, ''))
            .filter(version => typeof version === 'string' && /^\d+\.\d+\.\d+$/.test(version));
        stable.sort((a, b) => {
            const left = a.split('.').map(Number), right = b.split('.').map(Number);
            return right[0] - left[0] || right[1] - left[1] || right[2] - left[2];
        });
        if (stable.length === 0) throw new Error(`Packagist returned no stable version for ${name}.`);
        const installed = [...(lock.packages ?? []), ...(lock['packages-dev'] ?? [])].find(item => item.name === name)?.version?.replace(/^v/, '');
        return { name, installed: installed ?? null, latest: stable[0], current: installed === stable[0] };
    }));
}

async function main() {
    const manifest = JSON.parse(readFileSync(resolve(root, 'composer.json'), 'utf8'));
    const names = siblingRepositories(manifest);
    if (process.argv.includes('--checkout')) {
        // Never replace a person's sibling checkout. CI gets a fresh workspace.
        if (process.env.GITHUB_ACTIONS !== 'true') throw new Error('--checkout is restricted to GitHub Actions.');
        for (const name of names) {
            const target = resolve(root, '..', name);
            if (existsSync(target)) throw new Error(`Refusing to replace existing checkout: ${name}`);
            try {
                execFileSync('git', ['clone', '--depth', '1', '--branch', 'main', `https://github.com/Particle-Academy/${name}.git`, target], {
                    stdio: 'inherit', windowsHide: true, timeout: 60000,
                    env: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
                });
            } catch {
                throw new Error(`Required source Particle-Academy/${name} could not be checked out. An unpublished or inaccessible path dependency blocks CI; supply its reviewed remote source before treating the gate as operational.`);
            }
        }
    } else if (process.argv.includes('--composer-currency')) {
        const results = await checkComposerCurrency(manifest, JSON.parse(readFileSync(resolve(root, 'composer.lock'), 'utf8')));
        console.log(JSON.stringify(results, null, 2));
        if (results.some(result => !result.current)) throw new Error('First-party Composer releases are behind Packagist. Review and update their constraints and lock.');
    } else if (process.argv.includes('--currency')) {
        const results = await checkNpmCurrency(JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8')), JSON.parse(readFileSync(resolve(root, 'package-lock.json'), 'utf8')));
        console.log(JSON.stringify(results, null, 2));
        if (results.some(result => !result.current)) throw new Error('First-party npm packages are behind the registry. Review the new APIs and update the manifest and lock together.');
    } else {
        const evidence = inventory(root, names);
        writeFileSync(resolve(root, 'dogfood-evidence.json'), `${JSON.stringify(evidence, null, 2)}\n`);
        console.log(JSON.stringify(evidence, null, 2));
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch(error => { console.error(error.message); process.exitCode = 1; });
}
