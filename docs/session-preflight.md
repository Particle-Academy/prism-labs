# Session preflight

The first Home section and `GET /lab/preflight` share a read-only readiness
report. Both require the local environment and a raw loopback socket peer.
Forwarded IP headers do not grant access. The JSON response is not cached by
browsers. Refreshing it does not invoke a model or start a job.

The report checks four prerequisites:

- Required `default` and `scoring` queue workers have recent, idle heartbeats
  from native Laravel queue events. Records are scoped to this checkout, lock,
  Lab database namespace and retry window. Each worker removes only its own
  record when it stops. Busy, stale, stopped, unknown or misconfigured workers
  are unavailable. A worker timeout must be positive and shorter than the
  queue connection's `retry_after`; missing optional Laravel worker metadata
  or an unknown retry window remains unverified. Heartbeat failures never
  replace native job exceptions. Restart ordinary queue workers after updating
  the application so they load the heartbeat listeners; this report does not
  restart workers or alter their timeout/retry settings.
- The actual database connection identifies the configured Lab database, the
  required tables exist, and the native migration ledger has no pending
  installed migrations. The report never creates a database or migrates it.
  Home displays unavailable metrics when this check or ledger reads fail.
- The installed core-backed registry lists provider key **presence**. Only the
  selected text coordinator's key blocks readiness; optional missing keys are
  shown separately. Empty or malformed keys are absent. Keyless or unknown
  coordinators need separate verification and remain blocked. No credential
  value, fragment, length, digest, provider URL or project ID is returned.
- The official GitHub API identifies core `main`, then returns actual
  `push`/`main` runs at that SHA for tests, PHPStan, formatting, Require Checker
  and Factcheck. Every required workflow's latest run must have succeeded.
  Missing, queued, cancelled, failed, old-head and incomplete responses do not
  pass. Publish and pull-request runs cannot substitute for these checks.

Core status carries its observation time and main SHA. The file cache lasts
five minutes by default, independently of database availability. HTTP calls
use fixed GitHub endpoints, bounded time and response sizes, and no retries or
redirects. `LAB_GITHUB_READ_TOKEN` is an optional server-side read token; use
only a fine-grained token with Actions read access. Without one, public reads
are attempted and rate-limit failures stay unavailable. No shell credentials
are loaded. API references: [workflow runs](https://docs.github.com/en/rest/actions/workflow-runs)
and [Git references](https://docs.github.com/en/rest/git/refs).

`SessionPreflight::assertReady()` rechecks current core status and throws the
fixed `SessionNotReady` error on any missing prerequisite. This foundation
adds that API and read-only display. The next authenticated-approval
foundation must inventory and guard **every paid route and command**; existing
run entrypoints have not yet been wired to it. A green report grants neither
Wish's approval nor launch authority, and does not prove provider access or a
successful live model call. The session budget and explicit paid-run GO remain
separate requirements.

Local controls use SQLite memory, synthetic keys, HTTP fakes, native queue
events, and an actual isolated child worker. No live provider is used in CI.
