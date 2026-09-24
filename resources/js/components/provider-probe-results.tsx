export type FetchResult = { ok: boolean; code?: string | null; reason?: string; bytes?: number; mime_type?: string; preview?: string; truncated?: boolean };
export type CacheResult = { ok: boolean; reason?: string; runs: { answer: string; usage: Record<string, number | null> }[] };

export function FetchResultView({ result }: { result: FetchResult }) {
    return <div className="mt-4 space-y-2" aria-live="polite">
        <h3 className="font-bold">{result.ok ? 'Fetched' : result.code ? 'Refused' : 'Fetch failed'}</h3>
        {result.code && <p>Refusal code: <code>{result.code}</code></p>}
        {result.reason && <p>{result.reason}</p>}
        {result.ok && <><p>{result.bytes} bytes · {result.mime_type}</p><pre className="max-h-72 overflow-auto whitespace-pre-wrap break-words">{result.preview}</pre>{result.truncated && <p>Preview limited to the first 4,096 bytes.</p>}</>}
    </div>;
}

export function CacheEvidence({ result }: { result: CacheResult }) {
    return <div className="mt-4 space-y-3" aria-live="polite">
        {result.reason && <p role="alert">{result.reason}</p>}
        <table className="w-full text-left"><thead><tr><th>Call</th><th>Cache read tokens</th><th>Cache write tokens</th></tr></thead>
            <tbody>{result.runs.map((run, i) => <tr key={i}><th>{i + 1}</th><td>{run.usage.cache_read_input_tokens ?? 'Not reported'}</td><td>{run.usage.cache_write_input_tokens ?? 'Not reported'}</td></tr>)}</tbody>
        </table>
        {result.runs.length > 0 && result.runs.every(run => run.usage.cache_read_input_tokens === 0 && run.usage.cache_write_input_tokens === 0) && <p>No cache activity reported. A successful response alone does not demonstrate caching.</p>}
        {result.runs.map((run, i) => <details key={i}><summary>Call {i + 1} answer and full usage</summary><pre className="max-h-72 overflow-auto whitespace-pre-wrap break-words">{run.answer}</pre><pre>{JSON.stringify(run.usage, null, 2)}</pre></details>)}
    </div>;
}
