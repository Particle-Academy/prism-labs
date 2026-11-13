# Frozen RSI tests and run evidence

The owner reviews the complete v1 document before approving its digest: brief,
fixture hashes and sizes, assertions and expectations, instrument versions and
hashes, every lane identity and bound, revision and budget. A changed revision
needs approval again. Launch also requires the existing paid-intent prepare,
approve and GO steps. Agents cannot operate these consent controls.
The complete document, fixtures and assertions use server-encoded canonical
text, preserving valid large integers that a browser's parsed numeric props
cannot represent exactly. Evidence exports include these exact review strings.

The run room at `/runs/{id}?lane={lane-id}` and its evidence download require
the configured owner and the local-only application. Lane selection belongs to
that run. Historical evidence stays readable after expiry or revocation; that
read grants no permission for another call. Emergency stop prevents queued
work from starting. In-flight work can finish and retains its uncertain cost.

Every run remains **Unjudged**. Instrument pass/fail reports an individual
declared expectation; it confers no acceptance, package verdict, score or winner.
Missing measurements and unsupported instruments or profiles remain **Unknown**.
Declared references and observed native identities are displayed separately.
The run report does not capture or export raw output, workspace files,
transcripts or provider error content. Its output measurements persist only
hashes, sizes and bounded instrument results. Native Harness conversation
storage retains its existing separate behavior. Absent trace references remain
Unknown.

## Deterministic instrument profile v1.0.0

Each assertion pins an installed registry identity and SHA256 fingerprint of
the registry, schema evaluator and canonical encoder. A different fingerprint
or version yields Unknown. Inputs cannot select a class, script, URL or regex.

| Instrument | Expectation and observed quantity |
| --- | --- |
| `text.equals` | Exact UTF-8 string bytes, compared by SHA256. Only the observed hash and byte count are retained. |
| `json.schema` | The finite schema subset below. Invalid JSON fails; unsupported features or host numeric/depth limits yield Unknown. |
| `latency.maximum-ms` | Nonnegative maximum milliseconds for the monotonic native session `send` duration, including its host hooks. This is not provider-only latency. |
| `usage.maximum-tokens` | Nonnegative maximum of reported prompt plus completion tokens. Cache counters are not added separately; this is not a reconstructed billable-token total. Missing, negative, noninteger or overflowing counters yield Unknown. The native all-zero/no-cost fallback is treated as absent usage. |
| `cost.maximum-microusd` | Nonnegative maximum durable charge, available only when every native reservation has a known settlement. Pending or uncertain usage yields Unknown. These records are not a provider invoice. |

Maximum comparisons are inclusive. Each instrument has positive, negative and
missing/unsupported controls. No model-based judge or commentary runs here.

The JSON schema subset accepts one explicit `type`: `object`, `array`, `string`,
`integer`, `number`, `boolean` or `null`. Objects support `properties`, `required`
and boolean `additionalProperties` (default true). Arrays support one `items`
schema. All other keywords, including `$ref`, combinators and `pattern`, yield
Unknown rather than disappearing. Objects and arrays remain distinct; `1.0`
and `1e2` are mathematical integers.

Schema depth is at most 16 below the root, with at most 512 schema nodes and
128 properties or required names per object. JSON output is bounded to 262,144
bytes and parser depth 32. Numeric tokens are at most 128 bytes, finite and
within ±9,007,199,254,740,991. Nonzero underflow and fractional rounding that
would change integer classification yield Unknown. Numeric-looking string
contents are not numbers. These are host representation limits, not a claim
of general JSON Schema support.

The page refreshes active runs every eight seconds while visible. Export uses
the same bounded allowlist as the page and never follows legacy workspace or
raw Flow output. Missing or malformed native progress stays Unknown. Costs
show reserved maxima, pending maxima, accounted settlements, known charges and
uncertain-call counts separately; cancellation cannot refund unresolved work.

CI exercises synthetic native PHP inference and isolated SQLite/process
boundaries, plus the actual Fancy/Inertia pages, agent consent fences and SSR
without a server or provider requests. This does not establish live provider
acceptance, a deployed Lab, remote paid authority or invoice enforcement.
