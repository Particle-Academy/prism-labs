// A stand-in for @particle-academy/prism-acp's claudeAccounts API, for ClaudeAccountsTest. No CLI is run and no
// sign-in happens. The directory's last segment picks the behaviour.
import { basename } from 'node:path';

const HOSTS = ['claude.ai', 'claude.com', 'platform.claude.com', 'console.anthropic.com'];

export function claudeSignInUrl(candidate) {
    try {
        const url = new URL(candidate);
        return url.protocol === 'https:' && HOSTS.includes(url.hostname) && url.port === '' && url.username === '' && url.password === '' ? candidate : null;
    } catch {
        return null;
    }
}

// Reports, without the values, whether the helper's environment carried anything of the Lab's own.
const leaked = () => Object.keys(process.env).filter((name) => /^APP_KEY$|_API_KEY$|^CLAUDE_CONFIG_DIR$/i.test(name)).sort().join(',');

const status = (configDir, signedIn) => ({
    signedIn, method: signedIn ? 'subscription' : 'none', billing: signedIn ? 'subscription' : 'none', cliAuthMethod: 'x',
    email: signedIn ? 'person@example.test' : undefined, organizationName: leaked() || undefined,
    accessToken: 'must-not-pass-through', requestedConfigDir: configDir, configDirectoryMatches: true, loginExpiresAt: null, withheld: [],
});

const fail = (code) => Object.assign(new Error(`the library's own text ${code}`), { code });

export const claudeAccounts = Object.freeze({
    async describe(configDir) {
        return status(configDir, configDir === null || basename(configDir) === 'signed');
    },
    async login(configDir, { onUrl, signal }) {
        const name = basename(configDir);
        await onUrl(name === 'evil' ? 'https://claude.ai.example/steal' : 'https://claude.ai/oauth/authorize?state=fixture');
        if (name === 'slow' || name === 'evil') {
            await new Promise((resolve, reject) => {
                if (signal.aborted) return reject(fail('aborted'));
                const timer = setTimeout(() => reject(fail('timeout')), 20_000);
                signal.addEventListener('abort', () => { clearTimeout(timer); reject(fail('aborted')); }, { once: true });
            });
        }
        return status(configDir, true);
    },
    async logout(configDir) {
        if (basename(configDir) === 'missing') throw fail('missingDirectory');
        return status(configDir, false);
    },
    prepare(configDir, { sharedSessions }) {
        if (basename(configDir) === 'own') throw fail('realFolder');
        return { configDir, sharedSessions, store: sharedSessions ? '/store' : null, linked: sharedSessions ? 'created' : null };
    },
});
