import { build } from 'vite';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);

// Build the actual React displays in memory. No server, browser or provider calls.
const result = await build({
    configFile: false,
    logLevel: 'error',
    build: {
        write: false,
        minify: false,
        lib: { entry: resolve('tests/ui/provider-diagnostics.tsx'), formats: ['es'] },
        rollupOptions: {
            external: [/^node:/, /^react(?:\/|$)/, /^react-dom(?:\/|$)/],
            output: { paths: id => id.startsWith('node:') ? id : pathToFileURL(require.resolve(id)).href },
        },
    },
});
const output = (Array.isArray(result) ? result[0] : result).output.find(item => item.type === 'chunk');
try {
    await import(`data:text/javascript;base64,${Buffer.from(output.code).toString('base64')}`);
} catch (error) {
    // Avoid printing the entire data URL (the bundled source) in CI failures.
    console.error(error.message);
    process.exitCode = 1;
}
