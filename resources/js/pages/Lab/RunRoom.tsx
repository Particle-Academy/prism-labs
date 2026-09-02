import { Link, router } from '@inertiajs/react';
import { FileViewer } from '@particle-academy/fancy-code';
import { TreeNav, type TreeNodeData } from '@particle-academy/react-fancy';
import { useEffect, useRef, useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type Lane = { id: string; ordinal: number; language: string; harness: string; provider: string; model: string; status: string; last_seen_at?: string | null; workflow_run_id?: number | null; workspace_path?: string | null; started_at?: string | null; finished_at?: string | null; proof?: { text?: string; failure_class?: string; unreachable?: boolean; reason?: string } | null };
type Run = { id: string; status: string; started_at?: string | null; cancelled_at?: string | null; cancel_reason?: string | null; spec: { name: string; revision: number; digest: string; surface_mode: string; budgets: Record<string, number> }; lanes: Lane[] };
type Flow = { id: number; run_key: string; status: string; awaiting_node?: string | null; awaiting_kind?: string | null; error?: string | null; updated_at: string };
type Node = { run_key: string; node_id: string; status: string; attempts: number; error?: string | null; claimed_at?: string | null; completed_at?: string | null };
type Activity = { id: number; kind: string; level: string; summary: string; detail?: Record<string, unknown> | null; created_at: string };
type Operation = { id: string; kind: string; status: string; duration_ms?: number | null; metadata?: Record<string, unknown> | null; started_at: string };
type FileEntry = { path: string; name: string; kind: 'file' | 'dir'; size?: number; mtime?: string; hasChildren?: boolean };
type LaneInspection = { lane: Lane; activities: Activity[]; operations: Operation[]; files: FileEntry[] };
type OpenFile = { path: string; content?: string; language?: string; size: number; src?: string; mime?: string };
type Worker = { state: 'starting' | 'active' | 'stalled' | 'settled'; message: string };
type Commentary = { id: number; line: string; created_at: string };
type Receipt = { id: string; kind: string; digest: string; payload: unknown };
type LaneResult = { lane_id: string; ordinal: number; provider: string; model: string; status: string; scored: boolean; score: string | null; working_artifact: string | null; spec_digest: string | null; zero_learning: string | null; checks: Record<string, unknown>; receipts: Receipt[] };
type RunLearning = { ref: string; title: string; severity: string; severity_label: string; what_was_learned: string; evidence: string; why_it_matters: string; what_should_change: string | null; path: string };

/**
 * Poll intervals, chosen against a HARD REQUEST BUDGET rather than against how
 * live the page feels.
 *
 * Genie serves this site through one `php-cgi` worker, and `php-cgi` exits
 * after `PHP_FCGI_MAX_REQUESTS` — default 500 — after which nothing respawns it
 * and the site 502s until someone restarts it by hand. Measured: it died at
 * request 500 on the nose. So every poll spends a share of a fixed budget, and
 * the old 5s page + 3s inspector pair burned all 500 in about sixteen minutes,
 * which is roughly how long a benchmark run lasts. That is the crash.
 *
 * These numbers roughly double the time to exhaustion. `whenVisible` does more
 * than the numbers do: a backgrounded tab spends nothing at all, and a Run Room
 * left open on a second monitor was previously just as expensive as a watched
 * one.
 *
 * Slower polling costs less than it used to, because the commentary no longer
 * depends on it — `CallTheRunJob` keeps its own cadence server-side, so lines
 * accumulate whether or not anyone is looking and simply arrive on the next
 * poll.
 */
const PAGE_POLL_MS = 8000;
const INSPECTOR_POLL_MS = 5000;

/** Skip a tick entirely when nobody can see the result. */
function whenVisible(work: () => void): void {
    if (document.visibilityState === 'visible') work();
}

export default function RunRoom({ run, flows, nodes, worker, commentary, results, learning }: { run: Run; flows: Record<string, Flow>; nodes: Node[]; worker: Worker; commentary: Commentary[]; results: LaneResult[]; learning: RunLearning | null }) {
    const active = ['queued', 'ready', 'running'].includes(run.status);
    const [selectedLane, setSelectedLane] = useState<Lane | null>(null);
    useEffect(() => {
        if (!active) return;
        // No `preserveScroll` here: Inertia types `ReloadOptions` as
        // `Omit<VisitOptions, 'preserveScroll' | 'preserveState'>` because
        // `reload()` already sets both. Passing it was a type error that failed
        // the Build gate, and removing it changes nothing at runtime.
        const timer = window.setInterval(
            () => whenVisible(() => router.reload({ only: ['run', 'flows', 'nodes', 'worker', 'commentary', 'results', 'learning'] })),
            PAGE_POLL_MS,
        );
        return () => window.clearInterval(timer);
    }, [active]);

    const stop = () => {
        if (window.confirm('Emergency stop this entire run? Running and queued lanes will be cancelled and cannot resume. A provider request already in flight may finish its current network call, but its result will be discarded.')) {
            router.post(`/lab/benchmarks/runs/${run.id}/stop`, {}, { preserveScroll: true });
        }
    };
    const remove = () => {
        if (window.confirm('Permanently delete this run, its lane proof, and its Fancy Flow records?')) {
            router.delete(`/lab/benchmarks/runs/${run.id}`);
        }
    };

    return <LabShell title={run.spec.name} current="/lab/benchmarks" eyebrow={`Run ${run.id} · ${active ? 'live updates every 5s' : 'settled'}`}>
        <div className="lab-page-heading"><div><h1 className="lab-title">{run.spec.name}</h1><p className="lab-lead">Frozen revision {run.spec.revision} · {run.spec.digest.slice(0, 14)}… · {run.spec.surface_mode.replace('_', '+')}</p></div><div className="lab-run-actions">{active && <button type="button" className="k-btn k-btn--danger" onClick={stop}>Emergency stop</button>}{!active && <button type="button" className="k-btn k-btn--ghost" onClick={remove}>Delete run</button>}<Link href="/lab/benchmarks" className="k-btn k-btn--ghost">Back to Studio</Link></div></div>
        <Ticker lines={commentary} live={active} />
        {run.status === 'cancelled' && <section className="lab-fuse-tripped"><b>Fuse tripped</b><span>{run.cancel_reason ?? 'This run was stopped by the operator.'}</span><span>No queued lane can start and any late provider result is discarded. A new run is required to continue.</span></section>}
        {active && <section className={`lab-worker-state is-${worker.state}`}><i /><div><b>{worker.state === 'stalled' ? 'Worker attention required' : worker.state === 'active' ? 'Worker is running' : 'Preparing execution'}</b><span>{worker.message}</span></div></section>}
        <section className="lab-how"><b>What happens now</b><span>1. Each lane gets an isolated durable Flow run.</span><span>2. A worker claims it and contacts that language’s Harness.</span><span>3. The agent builds and drives the app, then submits proof and 0Learning.</span><span>4. PLabs scores only evidence-backed receipts.</span></section>
        <section className="lab-lanes">{run.lanes.map(lane => <LaneCard key={lane.id} lane={lane} worker={worker} selected={selectedLane?.id === lane.id} onSelect={() => setSelectedLane(lane)} flow={lane.workflow_run_id ? flows[String(lane.workflow_run_id)] : undefined} node={nodes.find(item => item.run_key === flows[String(lane.workflow_run_id)]?.run_key)} />)}</section>
        {selectedLane && <LaneInspector runId={run.id} lane={selectedLane} onClose={() => setSelectedLane(null)} />}
        <Results results={results} learning={learning} settled={!active} />
    </LabShell>;
}

/**
 * What the run PRODUCED — the half of this page that did not exist.
 *
 * The Run Room showed how lanes were going and then, the moment they finished,
 * nothing at all: the proof, the checks, the receipts and the run's own
 * 0Learning were all recorded and none were displayed. "PLabs scores only
 * evidence-backed receipts" was a claim the Lab made about itself on a page
 * that never showed a receipt.
 */
function Results({ results, learning, settled }: { results: LaneResult[]; learning: RunLearning | null; settled: boolean }) {
    if (results.length === 0 && !learning) {
        return settled
            ? <section className="lab-panel lab-results-empty"><b>No results</b><p>This run settled without any lane submitting a Proof-of-Working, and filed no learning. That is itself unusual — every terminal run is supposed to leave a 0L behind.</p></section>
            : null;
    }

    return <section className="lab-results">
        <div className="lab-panel-head"><span>Results</span><span>{results.length} lane(s) with proof</span></div>

        {learning && <article className={`lab-panel lab-learning is-${learning.severity}`}>
            <header><small>0Learning · {learning.severity_label}</small><h3>{learning.ref} — {learning.title}</h3></header>
            <dl>
                <dt>What was learned</dt><dd>{learning.what_was_learned}</dd>
                <dt>Evidence</dt><dd>{learning.evidence}</dd>
                <dt>Why it matters</dt><dd>{learning.why_it_matters}</dd>
                {learning.what_should_change && <><dt>What should change</dt><dd>{learning.what_should_change}</dd></>}
            </dl>
            <footer><code>{learning.path}</code></footer>
        </article>}

        {results.map(result => <article key={result.lane_id} className="lab-panel lab-result">
            <header>
                <div><small>Lane {result.ordinal} · {result.provider} · {result.model}</small><h3>{result.working_artifact ?? 'No artifact named'}</h3></div>
                {/* Absent, not zero. Nothing in the Lab computes a score yet, and
                    a "0" here would read as a verdict rather than a gap. */}
                <span className={`lab-score ${result.scored ? '' : 'is-unscored'}`}>{result.scored ? result.score : 'not scored'}</span>
            </header>

            <div className="lab-checks">
                {Object.entries(result.checks).map(([name, value]) => <div key={name}>
                    <b>{name.replaceAll('_', ' ')}</b>
                    <span>{typeof value === 'boolean' ? (value ? 'claimed pass' : 'claimed fail') : String(value)}</span>
                </div>)}
            </div>

            {result.zero_learning && <p className="lab-result-learning"><b>Lane 0Learning</b>{result.zero_learning}</p>}

            <details className="lab-receipts">
                <summary>{result.receipts.length} receipt(s) — the evidence a score would have to rest on</summary>
                {result.receipts.map(receipt => <div key={receipt.id} className="lab-receipt">
                    <b>{receipt.kind}</b>
                    <code title="Digest of the payload as submitted">{receipt.digest.slice(0, 16)}…</code>
                    <pre>{JSON.stringify(receipt.payload, null, 2)}</pre>
                </div>)}
            </details>
        </article>)}
    </section>;
}

// How long ago the lane last recorded anything, and whether that is worrying.
//
// "Agent is working" is printed from the moment a lane is claimed until the
// moment it fails, so a lane that has silently stopped looks exactly like one
// mid-build. The only thing that separates them is when it was last heard from.
function heartbeat(lane: Lane): { label: string; stalled: boolean } | null {
    if (!lane.last_seen_at) return null;
    const seconds = Math.max(0, Math.round((Date.now() - asDate(lane.last_seen_at).getTime()) / 1000));
    const label = seconds < 60 ? `${seconds}s ago` : seconds < 3600 ? `${Math.floor(seconds / 60)}m ${seconds % 60}s ago` : `${Math.floor(seconds / 3600)}h ago`;
    // A model call can legitimately run a couple of minutes before the next
    // tool result lands, so silence is only notable past that.
    return { label, stalled: seconds > 150 };
}

/**
 * The overseer calling the run, across the top of the screen.
 *
 * Scrolls only when there is something to say and the run is live. A ticker
 * that keeps sliding over a finished run reads as though the run is still
 * going, which is the one thing this page must never imply — the whole reason
 * the heartbeat exists on the cards below.
 */
function Ticker({ lines, live }: { lines: Commentary[]; live: boolean }) {
    const latest = useRef<HTMLDivElement | null>(null);
    useEffect(() => { latest.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'end' }); }, [lines.length]);

    if (lines.length === 0) {
        return live ? <section className="lab-ticker is-waiting"><b>PLab</b><span>Warming up — the overseer starts calling once the first lane reports.</span></section> : null;
    }

    return <section className={`lab-ticker ${live ? 'is-live' : 'is-settled'}`}>
        <b>PLab{live && <i />}</b>
        <div className="lab-ticker-rail">
            {lines.map((item, index) => <span key={item.id} ref={index === lines.length - 1 ? latest : undefined}>
                <time>{asDate(item.created_at).toLocaleTimeString()}</time>
                {item.line}
            </span>)}
        </div>
    </section>;
}

function LaneCard({ lane, flow, node, worker, selected, onSelect }: { lane: Lane; flow?: Flow; node?: Node; worker: Worker; selected: boolean; onSelect: () => void }) {
    const failure = lane.proof?.reason ?? node?.error ?? flow?.error ?? lane.proof?.failure_class;
    const beat = lane.status === 'running' ? heartbeat(lane) : null;
    const stage = lane.status === 'queued' && !node ? (worker.state === 'stalled' ? 'Worker unavailable · run stalled' : worker.state === 'active' ? 'Workspace ready · waiting behind active lane' : 'Workspace ready · waiting for worker claim') : lane.status === 'running' ? (beat ? (beat.stalled ? `No activity for ${beat.label.replace(' ago', '')} · may be stuck` : `Agent is working · last activity ${beat.label}`) : 'Agent is working') : lane.status === 'awaiting_proof' ? 'Waiting for Proof-of-Working' : lane.status;
    return <article className={`lab-panel lab-lane ${selected ? 'is-selected' : ''}`} onClick={onSelect} role="button" tabIndex={0} onKeyDown={event => { if (event.key === 'Enter') onSelect(); }}><div className="lab-lane-head"><div><small>Lane {lane.ordinal}</small><h2>{lane.language}</h2></div><span className={`lab-status is-${lane.status}`}>{lane.status}</span></div><dl><dt>Current step</dt><dd>{stage}</dd><dt>Harness</dt><dd>{lane.harness}</dd><dt>Provider</dt><dd>{lane.provider} · {lane.model}</dd><dt>Fancy Flow</dt><dd>{flow ? `${flow.run_key} · ${flow.status}` : 'Dispatching…'}</dd><dt>Attempts</dt><dd>{node?.attempts ?? 0}</dd><dt>Started</dt><dd>{lane.started_at ?? node?.claimed_at ?? 'Not claimed'}</dd>{beat && <><dt>Last heard from</dt><dd className={beat.stalled ? 'lab-beat-stalled' : undefined}>{beat.label}</dd></>}</dl>{failure && <div className="lab-lane-error"><b>Why it stopped</b><p>{failure}</p></div>}<span className="lab-inspect-link">Inspect activity and files →</span></article>;
}

function LaneInspector({ runId, lane, onClose }: { runId: string; lane: Lane; onClose: () => void }) {
    const [inspection, setInspection] = useState<LaneInspection | null>(null);
    const [opened, setOpened] = useState<OpenFile | null>(null);

    // Cursors, so each poll asks only for what arrived since the last one.
    // Kept in refs rather than state: the interval closure must read the
    // CURRENT value, and re-creating the interval on every tick to capture new
    // state would defeat the point.
    const sinceActivity = useRef<number | null>(null);
    // A TIMESTAMP, not a key: lab_operations ids are random UUIDs. The server
    // treats it inclusively because operations share a second, so the boundary
    // second comes back every poll and is de-duplicated on merge below.
    const sinceOperation = useRef<string | null>(null);

    const load = async (withFiles: boolean) => {
        const query = new URLSearchParams();
        if (sinceActivity.current !== null) query.set('since_activity', String(sinceActivity.current));
        if (sinceOperation.current !== null) query.set('since_operation', sinceOperation.current);
        query.set('files', withFiles ? '1' : '0');

        const response = await fetch(`/lab/benchmarks/runs/${runId}/lanes/${lane.id}?${query}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload: Omit<LaneInspection, 'files'> & { files: FileEntry[] | null; incremental: boolean } = await response.json();

        if (payload.activities.length) sinceActivity.current = Math.max(...payload.activities.map(item => item.id));
        if (payload.operations.length) sinceOperation.current = payload.operations.map(item => item.started_at).sort().at(-1) ?? sinceOperation.current;

        setInspection(previous => (!previous || !payload.incremental) ? { ...payload, files: payload.files ?? [] } : {
            lane: payload.lane,
            activities: [...previous.activities, ...payload.activities],
            operations: mergeById(previous.operations, payload.operations),
            // A null `files` means "unchanged, I did not ask" — never "empty".
            files: payload.files ?? previous.files,
        });

        // A new file event is the only reliable signal that the tree moved, so
        // the expensive listing is fetched when something wrote, not on a timer.
        return payload.activities.some(item => item.kind === 'file.written');
    };

    useEffect(() => {
        setOpened(null);
        setInspection(null);
        sinceActivity.current = null;
        sinceOperation.current = null;
        void load(true);

        const timer = window.setInterval(() => whenVisible(async () => {
            // Re-list the workspace only when this tick reported a write.
            if (await load(false)) void load(true);
        }), INSPECTOR_POLL_MS);
        return () => window.clearInterval(timer);
    }, [lane.id]);
    const openFile = async (value: string | string[] | null) => {
        if (typeof value !== 'string') return;
        const entry = inspection?.files.find(file => file.path === value);
        const mime = mediaMime(value);
        if (entry && mime) {
            setOpened({ path: value, size: entry.size ?? 0, mime, src: `/lab/benchmarks/runs/${runId}/lanes/${lane.id}/media?path=${encodeURIComponent(value)}` });
            return;
        }
        const response = await fetch(`/lab/benchmarks/runs/${runId}/lanes/${lane.id}/file?path=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json' } });
        if (response.ok) setOpened(await response.json());
    };
    const events = [...(inspection?.activities ?? []).map(item => ({ key: `a-${item.id}`, at: item.created_at, kind: item.kind, status: item.level, summary: item.summary, detail: item.detail })), ...(inspection?.operations ?? []).map(item => ({ key: `o-${item.id}`, at: item.started_at, kind: item.kind, status: item.status, summary: operationSummary(item), detail: item.metadata }))].sort((a, b) => a.at.localeCompare(b.at));

    return <section className="lab-inspector"><header><div><small>Lane inspector</small><h2>{lane.language} activity and workspace</h2><p>{inspection?.lane.workspace_path ?? 'Provisioning workspace…'}</p></div><button className="k-btn k-btn--ghost" type="button" onClick={onClose}>Close inspector</button></header><div className="lab-inspector-grid"><section className="lab-panel lab-stream"><div className="lab-panel-head"><span>Live activity</span><span>{events.length} events</span></div>{events.length === 0 ? <p className="lab-empty">Waiting for the first persisted agent event…</p> : events.map(event => <article key={event.key} className={`lab-event is-${event.status}`}><i /><div><time>{new Date(event.at).toLocaleTimeString()}</time><b>{event.summary}</b><small>{event.kind}</small>{event.detail && <details><summary>Event details</summary><pre>{JSON.stringify(event.detail, null, 2)}</pre></details>}</div></article>)}</section><section className="lab-panel lab-workspace"><div className="lab-panel-head"><span>Workspace files</span><span>{inspection?.files.length ?? 0} entries</span></div><div className="lab-ide"><aside>{inspection && <TreeNav nodes={toTree(inspection.files)} selectedId={opened?.path} onSelect={(id, node) => { if (node.type === 'file') void openFile(id); }} defaultExpandAll />}</aside><div className="lab-code-pane">{opened ? <><div className="lab-file-tab"><b>{opened.path}</b><span>{opened.size.toLocaleString()} bytes</span></div><FileViewer filename={opened.path} mime={opened.mime} src={opened.src} value={opened.content} readOnly style={{ height: 720 }} /></> : <p className="lab-empty">Choose a file from the workspace tree to inspect its current contents.</p>}</div></div></section></div></section>;
}

// The operations cursor is an inclusive timestamp, so the boundary second
// arrives again on the next poll. Merge by id and the repeat costs nothing.
function mergeById(previous: Operation[], incoming: Operation[]): Operation[] {
    if (!incoming.length) return previous;
    const seen = new Set(previous.map(item => item.id));
    return [...previous, ...incoming.filter(item => !seen.has(item.id))];
}

// Two timestamp shapes reach this page and they are not interchangeable.
// Eloquent serialises a model's `created_at` as ISO 8601 already; a value read
// through the query builder arrives as "Y-m-d H:i:s" with no zone. Appending
// `Z` to the first produces an invalid date, which is exactly what the ticker
// rendered before this existed.
function asDate(value: string): Date {
    return new Date(/[TZ]|[+-]\d{2}:?\d{2}$/.test(value) ? value : value.replace(' ', 'T') + 'Z');
}

function mediaMime(path: string): string | undefined {
    const extension = path.split('.').pop()?.toLowerCase();
    return ({ mp4: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime', mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', pdf: 'application/pdf' } as Record<string, string>)[extension ?? ''];
}

function operationSummary(operation: Operation): string {
    if (operation.kind === 'tool.call') return `Tool: ${String(operation.metadata?.tool_name ?? 'unknown')}`;
    if (operation.kind === 'generation.step') return `Model step ${String(operation.metadata?.step_index ?? '')} completed`;
    return operation.kind.replaceAll('.', ' ');
}

function toTree(entries: FileEntry[]): TreeNodeData[] {
    const roots: TreeNodeData[] = [];
    const dirs = new Map<string, TreeNodeData>();
    [...entries].sort((a, b) => a.path.localeCompare(b.path)).forEach(entry => {
        const node: TreeNodeData = { id: entry.path, label: entry.name, type: entry.kind === 'dir' ? 'folder' : 'file', ext: entry.kind === 'file' ? entry.name.split('.').pop() : undefined, children: entry.kind === 'dir' ? [] : undefined };
        if (entry.kind === 'dir') dirs.set(entry.path, node);
        const parentPath = entry.path.split('/').slice(0, -1).join('/') || '/';
        const parent = dirs.get(parentPath);
        if (parent?.children) parent.children.push(node); else roots.push(node);
    });
    return roots;
}
