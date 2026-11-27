import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync, renameSync, copyFileSync } from 'node:fs';
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

// Siblings the Lab consumes as BUILT npm packages from their working tree, not through Composer: config/acp.php loads
// ../prism-acp-ts/dist/index.js for /lab/accounts (scripts/claude-accounts.mjs). CI clones (--checkout-npm), checks and
// builds (--build-npm) them, so a candidate is exercised before it is published.
export const NPM_SIBLINGS = Object.freeze(['prism-acp-ts']);

// Harness ports the Lab exercises as a CONSUMER would (.github/workflows/harness-ports.yml): each is cloned (--checkout-port),
// checked against the org dependency policy, built, packed into a tarball or a wheel, and installed from that file into a
// throwaway project that runs tests/harness-ports/<lang>/. A candidate pin names the exact commit, as for any sibling.
export const PORT_SIBLINGS = Object.freeze(['prism-harness-ts', 'prism-harness-py']);

/**
 * Candidate pins (draft candidate PRs only): { "<sibling>": { "branch": "...", "sha": "<40 hex>" } }. A pinned sibling is
 * cloned from its branch and must be at exactly that commit. A pin naming anything but a known sibling fails closed.
 */
export function candidatePins(pins, known) {
    if (pins === null || typeof pins !== 'object' || Array.isArray(pins)) throw new Error('Candidate pins must be an object.');
    for (const [name, pin] of Object.entries(pins)) {
        if (!known.includes(name)) throw new Error(`Pinned ${name} is not a sibling dependency.`);
        if (pin === null || typeof pin !== 'object' || !/^[A-Za-z0-9][A-Za-z0-9._/-]*$/.test(pin.branch ?? '') || pin.branch.includes('..')
            || !/^[0-9a-f]{40}$/.test(pin.sha ?? '')) throw new Error(`Malformed pin for ${name}.`);
    }
    return pins;
}

/**
 * The ref a workflow checks an npm sibling out at: its candidate pin's exact commit, or main. acp.yml resolves this in a
 * job of its own and checks the sibling out with actions/checkout BEFORE fetching the org dependency policy, because
 * nothing that runs code may precede that fetch, and the policy must then check exactly the tree that gets built.
 */
export function npmSiblingRef(name, pins) {
    if (!NPM_SIBLINGS.includes(name)) throw new Error(`${name} is not an npm sibling.`);
    return pins[name]?.sha ?? 'main';
}

/**
 * The ref harness-ports.yml checks a harness port out at: its candidate pin's exact commit, or main. Resolved in a job of
 * its own, so the port's checkout is a plain actions/checkout that precedes the org dependency policy fetch.
 */
export function portSiblingRef(name, pins) {
    if (!PORT_SIBLINGS.includes(name)) throw new Error(`${name} is not a harness port sibling.`);
    return pins[name]?.sha ?? 'main';
}

/**
 * The pins a harness port lane honours. Only a pull_request run builds a pinned candidate: it runs with the pull
 * request's own read-only scope. A push to main or a workflow_dispatch runs in a branch's own context, where code a
 * candidate's build executes could reach that branch's shared state, so there every port is built from its main.
 */
export function portPins(pins, eventName) {
    return eventName === 'pull_request' ? pins : {};
}

/**
 * The same exact-verified-checkout gate cloneSibling applies: a pinned port must be at exactly the pinned commit, and that
 * commit must be the tip of the pinned branch, as a clone of that branch would be. An unpinned port is main, and logged.
 */
function verifyPortCheckout(name, pin) {
    if (!PORT_SIBLINGS.includes(name)) throw new Error(`${name} is not a harness port sibling.`);
    const target = resolve(root, '..', name);
    if (!existsSync(target)) throw new Error(`${name} is not checked out.`);
    const head = git(target, 'rev-parse', 'HEAD');
    const branch = pin?.branch ?? 'main';
    let tip;
    try {
        tip = execFileSync('git', ['ls-remote', '--exit-code', `https://github.com/Particle-Academy/${name}.git`, `refs/heads/${branch}`], {
            encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true, timeout: 30000,
            env: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
        }).trim().split(/\s+/)[0];
    } catch {
        throw new Error(`Branch ${branch} of Particle-Academy/${name} could not be read.`);
    }
    if (pin && head !== pin.sha) throw new Error(`Pinned ${name} is checked out at ${head}, expected ${pin.sha}.`);
    if (pin && tip !== head) throw new Error(`Pinned ${name} ${branch} is at ${tip}, but the checkout is at ${head}.`);
    console.log(`${pin ? 'Pinned' : 'Checked out'} ${name}: ${branch} at ${head}${!pin && tip !== head ? ` (${branch} has since moved to ${tip})` : ''}`);
}

function readPins(known) {
    const pinFile = resolve(root, 'dogfood-candidate-pins.json');
    return candidatePins(existsSync(pinFile) ? JSON.parse(readFileSync(pinFile, 'utf8')) : {}, known);
}

/** Clone one sibling from main, or from its pinned branch; a pinned clone must be at exactly the pinned commit. */
function cloneSibling(name, pin) {
    const target = resolve(root, '..', name);
    if (existsSync(target)) throw new Error(`Refusing to replace existing checkout: ${name}`);
    const branch = pin?.branch ?? 'main';
    try {
        execFileSync('git', ['clone', '--depth', '1', '--branch', branch, `https://github.com/Particle-Academy/${name}.git`, target], {
            stdio: 'inherit', windowsHide: true, timeout: 60000,
            env: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
        });
    } catch {
        throw new Error(`Required source Particle-Academy/${name} could not be checked out. An unpublished or inaccessible path dependency blocks CI; supply its reviewed remote source before treating the gate as operational.`);
    }
    const head = git(target, 'rev-parse', 'HEAD');
    if (pin) {
        if (head !== pin.sha) throw new Error(`Pinned ${name} ${branch} is at ${head}, expected ${pin.sha}.`);
        // Composer's dev-main aliases resolve against local branch main.
        if (branch !== 'main') git(target, 'checkout', '-q', '-B', 'main');
    }
    console.log(`${pin ? 'Pinned' : 'Checked out'} ${name}: ${branch} at ${head}`);
    return target;
}

/** The one artifact a port's build left in `dir`: a .tgz for the TypeScript port, a .whl for the Python port. */
function portArtifact(name, dir) {
    const extension = name === 'prism-harness-ts' ? '.tgz' : '.whl';
    const found = existsSync(dir) ? readdirSync(dir).filter(file => file.endsWith(extension)) : [];
    if (found.length !== 1) throw new Error(`Expected exactly one ${extension} for ${name} in ${dir}, found ${found.length}.`);
    return resolve(dir, found[0]);
}

/** A fixed program with fixed arguments, no shell. The port lanes run on Linux runners only. */
function exec(file, args, cwd) {
    if (process.platform === 'win32') throw new Error('The harness port lanes run on Linux runners only.');
    execFileSync(file, args, { cwd, stdio: 'inherit', timeout: 600000, env: { ...process.env, npm_config_ignore_scripts: 'true' } });
}

/**
 * Build a checked, verified harness port into the artifact a consumer installs, in $RUNNER_TEMP/artifact. TypeScript:
 * its own lock (`npm ci`, no lifecycle scripts), its own build, `npm pack`. Python: `pip wheel --no-deps`.
 */
function packPort(name) {
    const target = resolve(root, '..', name);
    const artifacts = resolve(process.env.RUNNER_TEMP, 'artifact');
    mkdirSync(artifacts);
    if (name === 'prism-harness-ts') {
        exec('npm', ['ci', '--ignore-scripts', '--no-audit', '--no-fund'], target);
        exec('npm', ['run', 'build'], target);
        exec('npm', ['pack', '--ignore-scripts', '--pack-destination', artifacts], target);
    } else {
        exec('python', ['-m', 'pip', 'wheel', '--no-deps', '--no-cache-dir', '--wheel-dir', artifacts, '.'], target);
    }
    console.log(`Packed ${name} at ${git(target, 'rev-parse', 'HEAD')}: ${basename(portArtifact(name, artifacts))}`);
}

/**
 * Install that artifact FILE into a throwaway consumer in $RUNNER_TEMP/consumer, as a consumer installs it, never a path
 * link or an editable install, and copy in the Lab's own scenario for that language.
 */
function installPort(name) {
    const artifact = portArtifact(name, resolve(process.env.RUNNER_TEMP, 'artifact'));
    const consumer = resolve(process.env.RUNNER_TEMP, 'consumer');
    mkdirSync(consumer);
    if (name === 'prism-harness-ts') {
        writeFileSync(resolve(consumer, 'package.json'), `${JSON.stringify({ name: 'lab-harness-consumer', private: true, type: 'module' }, null, 2)}
`);
        exec('npm', ['install', '--ignore-scripts', '--no-audit', '--no-fund', artifact], consumer);
        copyFileSync(resolve(root, 'tests', 'harness-ports', 'ts', 'scenario.mjs'), resolve(consumer, 'scenario.mjs'));
    } else {
        exec('python', ['-m', 'venv', resolve(consumer, 'venv')], consumer);
        exec(resolve(consumer, 'venv', 'bin', 'python'), ['-m', 'pip', 'install', '--no-cache-dir', '--only-binary=:all:', artifact], consumer);
        copyFileSync(resolve(root, 'tests', 'harness-ports', 'py', 'scenario.py'), resolve(consumer, 'scenario.py'));
    }
    console.log(`Installed ${basename(artifact)} into ${consumer}`);
}

function npm(cwd, args) {
    if (!args.every(arg => /^[A-Za-z0-9-]+$/.test(arg))) throw new Error('npm arguments must be plain words.');
    // npm is npm.cmd on Windows, which execFile cannot start directly. cmd is named explicitly rather than through
    // `shell: true`, and only the fixed, plain-word arguments above reach it.
    const [file, argv] = process.platform === 'win32' ? [process.env.ComSpec || 'cmd.exe', ['/d', '/s', '/c', 'npm', ...args]] : ['npm', args];
    execFileSync(file, argv, { cwd, stdio: 'inherit', windowsHide: true, timeout: 600000 });
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
        // Built npm siblings, when this workspace has them (CI's ACP source lane clones and builds them).
        npm_siblings: NPM_SIBLINGS.map(name => {
            const cwd = resolve(base, '..', name);
            if (!existsSync(cwd)) return { path: `../${name}`, checked_out: false };
            return {
                path: `../${name}`, checked_out: true, repository: repositoryIdentity(cwd), sha: git(cwd, 'rev-parse', 'HEAD'),
                dirty: git(cwd, 'status', '--porcelain', '--untracked-files=no') !== '', built: existsSync(resolve(cwd, 'dist', 'index.js')),
            };
        }),
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
        const latest = latestComposerStable(body, name);
        const installed = [...(lock.packages ?? []), ...(lock['packages-dev'] ?? [])].find(item => item.name === name)?.version?.replace(/^v/, '');
        return { name, installed: installed ?? null, latest, current: installed === latest };
    }));
}

function compareVersions(a, b) {
    const left = a.split('.').map(BigInt), right = b.split('.').map(BigInt);
    for (let i = 0; i < 3; i++) {
        if (left[i] !== right[i]) return left[i] > right[i] ? 1 : -1;
    }
    return 0;
}

function latestComposerStable(body, name) {
    const stable = (body.packages?.[name] ?? []).map(item => item.version?.replace(/^v/, ''))
        .filter(version => typeof version === 'string' && /^\d+\.\d+\.\d+$/.test(version));
    stable.sort((a, b) => compareVersions(b, a));
    if (stable.length === 0) throw new Error(`Packagist returned no stable version for ${name}.`);
    return stable[0];
}

export async function checkComposerAliasCurrency(manifest, fetchMetadata = fetch) {
    const aliases = siblingRepositories(manifest).flatMap(sibling => {
        const name = `particle-academy/${sibling}`, constraint = manifest.require[name];
        if (typeof constraint !== 'string') throw new Error(`Invalid path constraint for ${name}.`);
        // Bare dev-main has no release compatibility label. This gate checks
        // explicit path aliases.
        if (constraint === 'dev-main') return [];
        const repository = manifest.repositories.find(item => item.type === 'path' && basename(item.url) === sibling);
        // A noncanonical source explicitly labelled dev-main can coexist with
        // an exact prerelease installed from Packagist (the PHP ACP fixture).
        if (repository.canonical === false && repository.options?.versions?.[name] === 'dev-main'
            && /^\d+\.\d+\.\d+-[A-Za-z0-9.-]+$/.test(constraint)) return [];
        // Once that published consumer is stable, its exact pin must be current
        // too. An ahead-of-registry exact release is not an installable alias.
        if (repository.canonical === false && repository.options?.versions?.[name] === 'dev-main'
            && /^\d+\.\d+\.\d+$/.test(constraint)) return [{ name, alias: constraint, exact: true }];
        const match = /^dev-main as (\d+\.\d+\.\d+)$/.exec(constraint);
        if (!match) throw new Error(`Unsupported path alias for ${name}: expected dev-main as X.Y.Z.`);
        return [{ name, alias: match[1] }];
    });
    if (aliases.length === 0) throw new Error('No path aliases to check; refusing an empty currency inventory.');
    return Promise.all(aliases.map(async ({ name, alias, exact = false }) => {
        const response = await fetchMetadata(`https://repo.packagist.org/p2/${name}.json`, {
            headers: { 'Cache-Control': 'no-cache', Pragma: 'no-cache', Accept: 'application/json' },
            cache: 'no-store', signal: AbortSignal.timeout(15000),
        });
        if (!response.ok) throw new Error(`Packagist lookup failed for ${name}: ${response.status}`);
        const latest = latestComposerStable(await response.json(), name);
        const comparison = compareVersions(alias, latest);
        return { name, alias, latest, current: exact ? comparison === 0 : comparison >= 0, ahead: comparison > 0 };
    }));
}

async function main() {
    const manifest = JSON.parse(readFileSync(resolve(root, 'composer.json'), 'utf8'));
    const names = siblingRepositories(manifest);
    if (process.argv.includes('--npm-ref')) {
        const name = process.argv[process.argv.indexOf('--npm-ref') + 1];
        console.log(`ref=${npmSiblingRef(name, readPins([...names, ...NPM_SIBLINGS, ...PORT_SIBLINGS]))}`);
    } else if (process.argv.includes('--checkout') || process.argv.includes('--checkout-npm')) {
        // Never replace a person's sibling checkout. CI gets a fresh workspace.
        if (process.env.GITHUB_ACTIONS !== 'true') throw new Error('--checkout is restricted to GitHub Actions.');
        const pins = readPins([...names, ...NPM_SIBLINGS, ...PORT_SIBLINGS]);
        for (const name of process.argv.includes('--checkout') ? names : NPM_SIBLINGS) cloneSibling(name, pins[name]);
    } else if (process.argv.includes('--port-ref')) {
        const name = process.argv[process.argv.indexOf('--port-ref') + 1];
        console.log(`ref=${portSiblingRef(name, portPins(readPins([...names, ...NPM_SIBLINGS, ...PORT_SIBLINGS]), process.env.GITHUB_EVENT_NAME))}`);
    } else if (process.argv.includes('--verify-port')) {
        // After the org dependency policy has been checked against the checkout, and before anything builds it.
        if (process.env.GITHUB_ACTIONS !== 'true') throw new Error('--verify-port is restricted to GitHub Actions.');
        const name = process.argv[process.argv.indexOf('--verify-port') + 1];
        verifyPortCheckout(name, portPins(readPins([...names, ...NPM_SIBLINGS, ...PORT_SIBLINGS]), process.env.GITHUB_EVENT_NAME)[name]);
    } else if (process.argv.includes('--pack-port') || process.argv.includes('--install-port')) {
        // After --verify-port, never before: the org dependency policy has checked this exact tree.
        if (process.env.GITHUB_ACTIONS !== 'true' || !process.env.RUNNER_TEMP) throw new Error('--pack-port and --install-port are restricted to GitHub Actions.');
        const flag = process.argv.includes('--pack-port') ? '--pack-port' : '--install-port';
        const name = process.argv[process.argv.indexOf(flag) + 1];
        if (!PORT_SIBLINGS.includes(name)) throw new Error(`${name} is not a harness port sibling.`);
        (flag === '--pack-port' ? packPort : installPort)(name);
    } else if (process.argv.includes('--build-npm')) {
        // After the org dependency policy has been checked against each clone, never before.
        if (process.env.GITHUB_ACTIONS !== 'true') throw new Error('--build-npm is restricted to GitHub Actions.');
        for (const name of NPM_SIBLINGS) {
            const target = resolve(root, '..', name);
            if (!existsSync(target)) throw new Error(`${name} is not checked out; run --checkout-npm first.`);
            // Its own lockfile, exactly; no lifecycle scripts (tsc needs none), then its own build.
            npm(target, ['ci', '--ignore-scripts', '--no-audit', '--no-fund']);
            npm(target, ['run', 'build']);
            if (!existsSync(resolve(target, 'dist', 'index.js'))) throw new Error(`${name} built no dist/index.js.`);
            console.log(`Built ${name} at ${git(target, 'rev-parse', 'HEAD')}`);
        }
    } else if (process.argv.includes('--composer-alias-currency')) {
        const results = await checkComposerAliasCurrency(manifest);
        console.log(JSON.stringify(results, null, 2));
        const ahead = results.filter(result => result.ahead);
        if (ahead.length) console.log(`Notice: path aliases ahead of published stable releases: ${ahead.map(result => `${result.name} (${result.alias} > ${result.latest})`).join(', ')}.`);
        if (results.some(result => !result.current)) throw new Error('Path compatibility aliases are behind Packagist. Review and update their labels without lowering child dependency floors.');
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
