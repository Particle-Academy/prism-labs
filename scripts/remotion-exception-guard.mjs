const version = value => {
    if (!/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(value ?? '')) throw new Error('Registry version is not an exact stable release.');
    const parts = value.split('.').map(Number);
    if (!parts.every(Number.isSafeInteger)) throw new Error('Registry version exceeds safe integer bounds.');
    return parts;
};
const compare = (a, b) => { const left = version(a), right = version(b); return left[0] - right[0] || left[1] - right[1] || left[2] - right[2]; };

async function registry(name, release) {
    const response = await fetch(`https://registry.npmjs.org/${encodeURIComponent(name)}/${encodeURIComponent(release)}`, { redirect: 'error', signal: AbortSignal.timeout(15_000), headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' } });
    if (!response.ok) throw new Error('Cannot verify the current Remotion registry dependency pins.');
    const reader = response.body.getReader();
    const chunks = [];
    let bytes = 0;
    while (true) {
        const part = await reader.read();
        if (part.done) break;
        bytes += part.value.length;
        if (bytes > 2_000_000) { await reader.cancel(); throw new Error('Registry metadata exceeded its bound.'); }
        chunks.push(Buffer.from(part.value));
    }
    const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    if (body.name !== name) throw new Error('Registry returned another package.');
    version(body.version);
    return body;
}

export async function remotionFixStatus(fetchMetadata = registry) {
    const [bundler, latestStudio] = await Promise.all([fetchMetadata('@remotion/bundler', 'latest'), fetchMetadata('@remotion/studio', 'latest')]);
    version(bundler.version); version(latestStudio.version);
    const studioPin = bundler.dependencies?.['@remotion/studio'];
    if (typeof studioPin !== 'string') throw new Error('Remotion bundler dependency shape changed; re-review the exception.');
    version(studioPin);
    const referencedStudio = studioPin === latestStudio.version ? latestStudio : await fetchMetadata('@remotion/studio', studioPin);
    if (referencedStudio.version !== studioPin) throw new Error('Registry did not return the pinned Studio version.');
    const studios = [latestStudio, referencedStudio];
    const pins = [];
    for (const studio of studios) {
        const range = studio.dependencies?.zod;
        if (typeof range !== 'string') throw new Error('Studio Zod dependency changed; re-review the exception.');
        let resolved = range;
        if (range.startsWith('^') || range.startsWith('~')) {
            const floor = range.slice(1);
            const latest = await fetchMetadata('zod', 'latest');
            const selected = version(latest.version), minimum = version(floor);
            if (selected[0] !== minimum[0] || (range.startsWith('~') && selected[1] !== minimum[1]) || compare(latest.version, floor) < 0) throw new Error('Zod range needs a new registry-resolution review.');
            resolved = latest.version;
        }
        version(resolved);
        pins.push({ studio: studio.version, range, resolved });
    }
    return { bundler: bundler.version, latestStudio: latestStudio.version, pins, fixAvailable: pins.some(pin => compare(pin.resolved, '4.6.6') >= 0) };
}
