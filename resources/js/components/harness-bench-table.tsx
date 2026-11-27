import { Badge, Callout, Table, Text } from '@particle-academy/react-fancy';

/**
 * G-393: the latest imported harness-benchmark run, cost beside score.
 *
 * Every figure is the calibration's own measurement on its frozen subset, read
 * from a results file whose GitHub attestation verified on import. It is not a
 * leaderboard score: the boards run the full set, so their rows are not
 * comparable to these and are deliberately not shown in this table.
 */
type Tokens = { fresh_input: number; cache_write: number; cache_read: number; output: number };
type Delta = { score?: number | null; usd_per_solved?: number | null; cache_read_share?: number | null; steps_per_attempt?: number | null; tokens_per_attempt?: Tokens };
export type HarnessBenchRow = {
    benchmark: string; arm: string; neutral: boolean; score: number | null; attempts: number; usd_per_solved: number | null;
    tokens_per_attempt: Tokens | null; cache_read_share: number | null; steps_per_attempt: number | null;
    pass_hat_k: Record<string, number> | null; delta: Delta | null; shared_tasks: number | null;
};
export type HarnessBench = {
    ftq_id: string; model: string; reasoning_effort: string; harness_version: string; spent_usd: number; cap_usd: number;
    workflow_run_id: string | null; verified_at: string; rows: HarnessBenchRow[];
};

const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);
const usd = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `$${v.toFixed(4)}`);
const num = (v: number | null | undefined) => (v === null || v === undefined ? '—' : v.toLocaleString(undefined, { maximumFractionDigits: 1 }));
const signed = (v: number | null | undefined, format: (x: number) => string) => (v === null || v === undefined ? '' : ` (${v >= 0 ? '+' : '−'}${format(Math.abs(v))})`);
const totalTokens = (t: Tokens | null | undefined) => (t ? t.fresh_input + t.cache_write + t.cache_read + t.output : null);

export function HarnessBenchTable({ bench }: { bench: HarnessBench | null }) {
    if (bench === null) {
        return <Callout color="zinc" data-testid="harness-bench-empty">No harness-benchmark run has been imported. Results arrive as a signed PR from harness-bench.yml and are imported with php artisan lab:import-eval.</Callout>;
    }
    return <section data-testid="harness-bench">
        <Text color="muted" size="sm">{bench.model} · effort {bench.reasoning_effort} · prism-harness {bench.harness_version} · spent {usd(bench.spent_usd)} of ${bench.cap_usd.toFixed(2)} · attestation verified {new Date(bench.verified_at).toLocaleString()}. Deltas compare each arm with the neutral harness on the tasks both attempted.</Text>
        <Table className="overflow-x-auto">
            <Table.Head>
                <Table.Row>
                    <Table.Column label="Benchmark" />
                    <Table.Column label="Harness" />
                    <Table.Column label="Score" />
                    <Table.Column label="$ / solved" />
                    <Table.Column label="Tokens / task" />
                    <Table.Column label="Cache-read share" />
                    <Table.Column label="Steps / task" />
                </Table.Row>
            </Table.Head>
            <Table.Body>
                {bench.rows.map(row => <Table.Row key={`${row.benchmark}:${row.arm}`} data-arm={row.arm}>
                    <Table.Cell>{row.benchmark === 'tau2' ? 'τ²-airline' : 'Terminal-Bench 4.1 CPU subset'}</Table.Cell>
                    <Table.Cell>{row.arm} {row.neutral ? <Badge color="zinc" size="sm">neutral</Badge> : null}<Text color="muted" size="sm">{row.attempts} attempts{row.shared_tasks !== null ? ` · ${row.shared_tasks} shared tasks` : ''}</Text></Table.Cell>
                    <Table.Cell>{pct(row.score)}{signed(row.delta?.score, v => `${(v * 100).toFixed(1)} pt`)}{row.pass_hat_k ? <Text color="muted" size="sm">pass^k {Object.entries(row.pass_hat_k).map(([k, v]) => `${k}: ${pct(v)}`).join(' · ')}</Text> : null}</Table.Cell>
                    <Table.Cell>{usd(row.usd_per_solved)}{signed(row.delta?.usd_per_solved, v => `$${v.toFixed(4)}`)}</Table.Cell>
                    <Table.Cell>{num(totalTokens(row.tokens_per_attempt))}{row.tokens_per_attempt ? <Text color="muted" size="sm">fresh {num(row.tokens_per_attempt.fresh_input)} · write {num(row.tokens_per_attempt.cache_write)} · read {num(row.tokens_per_attempt.cache_read)} · out {num(row.tokens_per_attempt.output)}</Text> : null}</Table.Cell>
                    <Table.Cell>{pct(row.cache_read_share)}{signed(row.delta?.cache_read_share, v => `${(v * 100).toFixed(1)} pt`)}</Table.Cell>
                    <Table.Cell>{num(row.steps_per_attempt)}{signed(row.delta?.steps_per_attempt, v => v.toFixed(1))}</Table.Cell>
                </Table.Row>)}
            </Table.Body>
        </Table>
    </section>;
}
