# Frozen RSI test contracts

Slice 2 PR1 persists definitions and owner approvals. Native execution, shared
cost admission, run-room instruments and draft chat follow in separate PRs.
There is no reviewer or acceptance decision in this contract.

`FrozenTestDefinition::fromArray()` validates a bounded JSON definition:

- `brief`: the task, up to 16,000 bytes.
- `fixtures`: named SHA-256/byte-count descriptors, not paths or executable data.
- `instruments`: named exact versions and SHA-256 digests.
- `assertions`: named instrument references and JSON expected values.
- `lanes`: stable IDs, language, package/ref, provider/model, harness/ref,
  mode/optional agent, and finite declared bounds.
- `cost_cap_microusd`: a positive integer, at most the $5 session limit.

Refs name an exact release or full source commit plus a SHA-256 content digest.
These are declared inputs to approval. The later executor must compare actual
material and record its identity; a draft is not evidence that a package exists,
a provider is available, a lane is supported, or a cap can be enforced.
Unavailable lanes must be reported explicitly, never removed from the test.
The contract accepts language/provider/media declarations independently of the
current executors. Native admission must verify the required limits and trusted
pricing for each actual capability before any call.

Bounds include `max_calls`, `max_steps`, `timeout_seconds` and a nonempty
`limits` map of finite nonnegative integer `max_*` values (for example input/
output tokens, tool calls or image count). No field is a command, class name
to instantiate, executable assertion or permission grant.

`BenchmarkDesigner::draftTest()` reuses BenchmarkSpec and canonical SHA-256
freezing with the `rsi-test-v1` archetype and no reviewer rubric. Approval uses
the existing authenticated owner boundary and records the owner, time and
exact digest. Drafting, requesting approval and approval itself buy no work.
`reviseTest()` appends a fresh draft, preserving its parent's ID/digest and all
old inputs and approval history. It copies no approval or GO. The existing
unique name/revision index remains the final concurrent-allocation barrier.
Requesting approval locks and reloads the current row, verifies its digest and
checks its current state; stale copies cannot demote an approved revision.

Every fixture, assertion, instrument, lane identity, declared ref, bound, cap,
surface and revision participates in the frozen digest. Object key order is
canonical; list order is meaningful. Malformed, recursive, oversized, ambiguous
or unbounded input is refused with a fixed field-level error that does not echo
payload values. Later execution persists a shuffled order once per run.

RSI tests currently refuse the legacy scored launch path even with paid GO.
They cannot inherit its scorer/commentary dispatch. Native shared durable
admission and an unjudged runner must be delivered before execution is enabled.
Missing Harness cross-process reservation support must be reported upstream
before wiring; no Lab-specific transport or authority bypass is permitted.

Missing measurements, including exports, will remain Unknown; outages are
environment outcomes. Capture stays off by default, with hashes and bounded
excerpts in the run room. Draft chat will use a separate session-scoped
`agent.send` approval/GO with an exact cap and expiry. Its turns draw the same
reservation, which cannot fund tests or lanes. These later behaviors are
requirements, not execution claims made by this PR.
