import { Link, router } from '@inertiajs/react';
import { FileViewer } from '@particle-academy/fancy-code';
import { TreeNav, type TreeNodeData } from '@particle-academy/react-fancy';
import { useEffect, useState } from 'react';
import { LabShell } from '../../components/lab-shell';

type Lane = { id: string; ordinal: number; language: string; harness: string; provider: string; model: string; status: string; workflow_run_id?: number | null; workspace_path?: string | null; started_at?: string | null; finished_at?: string | null; proof?: { text?: string; failure_class?: string; unreachable?: boolean; reason?: string } | null };
type Run = { id: string; status: string; started_at?: string | null; cancelled_at?: string | null; cancel_reason?: string | null; spec: { name: string; revision: number; digest: string; surface_mode: string; budgets: Record<string, number> }; lanes: Lane[] };
type Flow = { id: number; run_key: string; status: string; awaiting_node?: string | null; awaiting_kind?: string | null; error?: string | null; updated_at: string };
type Node = { run_key: string; node_id: string; status: string; attempts: number; error?: string | null; claimed_at?: string | null; completed_at?: string | null };
type Activity = { id: number; kind: string; level: string; summary: string; detail?: Record<string, unknown> | null; created_at: string };
type Operation = { id: string; kind: string; status: string; duration_ms?: number | null; metadata?: Record<string, unknown> | null; started_at: string };
type FileEntry = { path: string; name: string; kind: 'file' | 'dir'; size?: number; mtime?: string; hasChildren?: boolean };
type LaneInspection = { lane: Lane; activities: Activity[]; operations: Operation[]; files: FileEntry[] };
type OpenFile = { path: string; content?: string; language?: string; size: number; src?: string; mime?: string };
type Worker = { state: 'starting' | 'active' | 'stalled' | 'settled'; message: string };

export default function RunRoom({ run, flows, nodes, worker }: { run: Run; flows: Record<string, Flow>; nodes: Node[]; worker: Worker }) {
    const active = ['queued', 'ready', 'running'].includes(run.status);
    const [selectedLane, setSelectedLane] = useState<Lane | null>(null);
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(() => router.reload({ only: ['run', 'flows', 'nodes', 'worker'], preserveScroll: true }), 5000);
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
        {run.status === 'cancelled' && <section className="lab-fuse-tripped"><b>Fuse tripped</b><span>{run.cancel_reason ?? 'This run was stopped by the operator.'}</span><span>No queued lane can start and any late provider result is discarded. A new run is required to continue.</span></section>}
        {active && <section className={`lab-worker-state is-${worker.state}`}><i /><div><b>{worker.state === 'stalled' ? 'Worker attention required' : worker.state === 'active' ? 'Worker is running' : 'Preparing execution'}</b><span>{worker.message}</span></div></section>}
        <section className="lab-how"><b>What happens now</b><span>1. Each lane gets an isolated durable Flow run.</span><span>2. A worker claims it and contacts that language’s Harness.</span><span>3. The agent builds and drives the app, then submits proof and 0Learning.</span><span>4. PLabs scores only evidence-backed receipts.</span></section>
        <section className="lab-lanes">{run.lanes.map(lane => <LaneCard key={lane.id} lane={lane} worker={worker} selected={selectedLane?.id === lane.id} onSelect={() => setSelectedLane(lane)} flow={lane.workflow_run_id ? flows[String(lane.workflow_run_id)] : undefined} node={nodes.find(item => item.run_key === flows[String(lane.workflow_run_id)]?.run_key)} />)}</section>
        {selectedLane && <LaneInspector runId={run.id} lane={selectedLane} onClose={() => setSelectedLane(null)} />}
    </LabShell>;
}

function LaneCard({ lane, flow, node, worker, selected, onSelect }: { lane: Lane; flow?: Flow; node?: Node; worker: Worker; selected: boolean; onSelect: () => void }) {
    const failure = lane.proof?.reason ?? node?.error ?? flow?.error ?? lane.proof?.failure_class;
    const stage = lane.status === 'queued' && !node ? (worker.state === 'stalled' ? 'Worker unavailable · run stalled' : worker.state === 'active' ? 'Workspace ready · waiting behind active lane' : 'Workspace ready · waiting for worker claim') : lane.status === 'running' ? 'Agent is working' : lane.status === 'awaiting_proof' ? 'Waiting for Proof-of-Working' : lane.status;
    return <article className={`lab-panel lab-lane ${selected ? 'is-selected' : ''}`} onClick={onSelect} role="button" tabIndex={0} onKeyDown={event => { if (event.key === 'Enter') onSelect(); }}><div className="lab-lane-head"><div><small>Lane {lane.ordinal}</small><h2>{lane.language}</h2></div><span className={`lab-status is-${lane.status}`}>{lane.status}</span></div><dl><dt>Current step</dt><dd>{stage}</dd><dt>Harness</dt><dd>{lane.harness}</dd><dt>Provider</dt><dd>{lane.provider} · {lane.model}</dd><dt>Fancy Flow</dt><dd>{flow ? `${flow.run_key} · ${flow.status}` : 'Dispatching…'}</dd><dt>Attempts</dt><dd>{node?.attempts ?? 0}</dd><dt>Started</dt><dd>{lane.started_at ?? node?.claimed_at ?? 'Not claimed'}</dd></dl>{failure && <div className="lab-lane-error"><b>Why it stopped</b><p>{failure}</p></div>}<span className="lab-inspect-link">Inspect activity and files →</span></article>;
}

function LaneInspector({ runId, lane, onClose }: { runId: string; lane: Lane; onClose: () => void }) {
    const [inspection, setInspection] = useState<LaneInspection | null>(null);
    const [opened, setOpened] = useState<OpenFile | null>(null);
    const load = async () => {
        const response = await fetch(`/lab/benchmarks/runs/${runId}/lanes/${lane.id}`, { headers: { Accept: 'application/json' } });
        if (response.ok) setInspection(await response.json());
    };
    useEffect(() => {
        setOpened(null); void load();
        const timer = window.setInterval(() => void load(), 3000);
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
