export type RunDiagnostics = {
    code: string;
    run_id: string | null;
    incomplete_reason: string | null;
    output: unknown[];
    citations: unknown[];
    usage: Record<string, number | null> | null;
};

export function RunDiagnosticsView({ diagnostics }: { diagnostics?: RunDiagnostics | null }) {
    if (!diagnostics) return null;
    return <section className="mt-4 space-y-3" aria-label="Unsuccessful run diagnostics">
        <h3 className="font-bold">Run diagnostics</h3>
        <dl className="grid gap-2 sm:grid-cols-3">
            <div><dt>Code</dt><dd>{diagnostics.code}</dd></div>
            <div><dt>Run ID</dt><dd className="break-all">{diagnostics.run_id ?? 'Not reported'}</dd></div>
            <div><dt>Stop reason</dt><dd>{diagnostics.incomplete_reason ?? 'Not reported'}</dd></div>
        </dl>
        <p>Partial output is provisional, not a completed answer. Provider content is shown as text.</p>
        {(['output', 'citations', 'usage'] as const).map(key => <div key={key}>
            <h4 className="font-semibold">{key === 'output' ? 'Partial output' : key === 'citations' ? 'Citations' : 'Usage'}</h4>
            <pre className="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded border p-3 text-xs">{diagnostics[key] === null ? 'Not reported' : JSON.stringify(diagnostics[key], null, 2)}</pre>
        </div>)}
    </section>;
}
