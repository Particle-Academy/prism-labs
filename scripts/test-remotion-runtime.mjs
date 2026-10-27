import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const runtime = join(root, 'tools/remotion-runtime');
const cli = join(runtime, 'node_modules/@remotion/cli/remotion-cli.js');
if (!existsSync(cli)) throw new Error('Install the committed Remotion runtime with npm ci in tools/remotion-runtime first.');
const directory = mkdtempSync(join(runtime, '.render-check-'));
const allowed = process.platform === 'win32' ? ['PATH', 'SystemRoot', 'TEMP', 'TMP', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE'] : ['PATH', 'HOME', 'TMPDIR'];
const env = Object.fromEntries(allowed.filter(name => process.env[name] !== undefined).map(name => [name, process.env[name]]));
const entry = join(directory, 'entry.tsx');
const output = join(directory, 'control.mp4');
try {
    writeFileSync(entry, `import React from 'react';
import {AbsoluteFill, Composition, registerRoot, useCurrentFrame} from 'remotion';
const Frame = () => <AbsoluteFill style={{backgroundColor: useCurrentFrame() < 15 ? '#113355' : '#cc8844'}} />;
registerRoot(() => <Composition id="OwnedControl" component={Frame} durationInFrames={30} fps={30} width={320} height={180} />);
`);
    const rendered = spawnSync(process.execPath, [cli, 'render', entry, 'OwnedControl', output, '--codec=h264', '--concurrency=1'], { cwd: runtime, env, encoding: 'utf8', windowsHide: true, timeout: 300_000, maxBuffer: 4 * 1024 * 1024 });
    if (rendered.error || rendered.status !== 0) throw new Error('Deterministic Remotion render failed: ' + (rendered.error?.message ?? rendered.stderr?.slice(-2000)));
    const probe = process.platform === 'win32' ? join(runtime, 'node_modules/@remotion/compositor-win32-x64-msvc/ffprobe.exe')
        : process.platform === 'linux' && process.arch === 'x64' ? join(runtime, 'node_modules/@remotion/compositor-linux-x64-gnu/ffprobe') : 'ffprobe';
    const inspected = spawnSync(probe, ['-v', 'error', '-show_entries', 'format=duration,size:stream=codec_name,width,height', '-of', 'json', output], { env, encoding: 'utf8', windowsHide: true, timeout: 30_000 });
    if (inspected.error || inspected.status !== 0) throw new Error('Independent ffprobe inspection failed.');
    const metadata = JSON.parse(inspected.stdout);
    const stream = metadata.streams?.[0];
    if (stream?.codec_name !== 'h264' || stream.width !== 320 || stream.height !== 180 || Math.abs(Number(metadata.format?.duration) - 1) > 0.05) throw new Error('Rendered artifact does not match the declared control.');
    const bytes = readFileSync(output);
    if (bytes.length === 0) throw new Error('Renderer produced an empty artifact.');
    const receipt = { kind: 'remotion.render.control', platform: process.platform, frames: 30, fps: 30, width: stream.width, height: stream.height, codec: stream.codec_name, durationSeconds: Number(metadata.format.duration), bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex'), runtimeLockSha256: createHash('sha256').update(readFileSync(join(runtime, 'package-lock.json'))).digest('hex') };
    writeFileSync(join(root, 'remotion-render-evidence.json'), JSON.stringify(receipt, null, 2) + '\n');
    console.log(JSON.stringify(receipt, null, 2));
} finally {
    if (dirname(realpathSync(directory)) !== realpathSync(runtime)) throw new Error('Owned render cleanup escaped runtime directory.');
    rmSync(directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
