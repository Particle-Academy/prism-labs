# Dogfood evidence and keeping the Lab current

The Lab's CI checks application behavior without provider keys or billable
calls. Live provider runs remain local and require the operator to submit them.
Neither a green build nor agents agreeing establishes alignment or safety.

## What runs

`tests.yml` and `build.yml` run on pull requests, main pushes, manual dispatch,
and daily schedules. Both have `locked` and `current` lanes:

- PHP installs the application lock against fresh sibling `main` checkouts.
  The current lane also resolves the latest versions allowed by Composer.
  Every path checkout comes from `composer.json`; adding a satellite cannot
  silently omit it from CI. This includes ACP.
  First-party packages installed from Packagist are compared with its current
  stable releases too, including the pinned workflow engine.
  Before checkout or installation, the current PHP lane also compares every
  explicit `dev-main as X.Y.Z` path label with the latest stable release from
  uncached Packagist. A label below that release fails CI even when the sibling
  source itself is current. Child dependency floors remain authoritative.
  Ahead-of-release aliases remain allowed for testing unpublished sibling main;
  the result reports `ahead: true` and prints a notice so they stay visible.
  The noncanonical PHP ACP path's exact stable pin is also checked against the
  latest stable release; stale and unpublished pins fail. Its current-source
  lane still uses the explicit development alias.
- The frontend installs the npm lock, then the current lane resolves allowed
  updates. It also compares every direct first-party npm package with the
  uncached registry. A release outside the current constraint fails currency
  rather than being silently ignored. Dependabot groups first-party npm
  upgrades into reviewable pull requests.
- Both run their dependency audit. PHP runs the full suite and the explicit,
  nonempty `dogfood` group. The frontend typechecks, checks provider displays,
  exercises the native Human+ form bridge, runs Chromium against the actual application entry and pages, and builds
  both client and SSR bundles.
- Factcheck uses the current shared checker and trust rubric from prism-parity.
  The rubric checks the quality of the shared security corpora; it does not
  certify this application's live provider behavior.

PHP artifacts include the Lab SHA, every sibling SHA and dirty state, both lock
hashes, and the resolved Composer lock. Frontend artifacts include its resolved
npm lock. These identify what passed. Path aliases in Composer are constraint
compatibility labels, not proof of the published release installed by a user.
These jobs exercise source checkouts, not downloaded release archives.

### Model listings and prices

The Models page uses provider listings through the installed Prism package,
caches each result for ten minutes and provides an explicit refresh. Missing
credentials, unsupported listing methods and failed requests remain visible
as provider notices with no fallback model rows. Failed listings are cached
for sixty seconds. Authentication or access rejection (401/403) has its own
fixed notice directing the operator to update Lab credentials or permissions;
provider bodies, URLs and keys are never displayed. An unsupported notice means
the installed package lacks that listing implementation, not that the provider
has no API. Saved choices survive an unavailable provider; saving
changes only providers whose listing succeeded. A withdrawn saved model is
shown disabled until explicitly removed, and cannot start a benchmark.
Replicate's public catalogue
is labelled as such. Returned names and IDs are untrusted text, never HTML or
evidence of a model's capability. New models are disabled until selected;
withdrawn models are removed from the effective allow-list, including defaults.
Their saved selection is preserved separately from that effective permission.

Prices are dated USD list rates per million tokens, joined by exact model IDs.
Unknown rates remain visible. OpenAI standard text-token prices include explicit
context tiers and the provider's 30-minute and 12-hour cache-write durations.
Snapshot IDs receive a rate only when an official model page explicitly names
that default snapshot; other historical dates are not inferred from an alias.
Mixed-modality, audio, image, video, embedding and other non-text units remain
unknown in this text-token view. Anthropic cache writes retain their distinct
5-minute and 1-hour durations and Haiku's two context tiers. The dated Opus 4.5
and Sonnet 4.5 entries cite their own official model pages. Rates older than
thirty days, or past an explicitly dated promotion, show a checking notice.
Dated legacy/deprecated labels cite the providers' model pages separately
from prices. They do not change the operator's permissions or default IDs.
Incidental commentary and scoring choose by numeric input price among the
operator's permitted, priced models. Refresh does not write the saved policy.
Scoring can release its job every two minutes for up to thirty minutes only
when a permitted, priced provider's listing is temporarily unreachable and
before any inference has been sent. Missing configuration, unsupported listing,
authentication/access rejection, operator choices and exhausted retries show
a specific reason without retrying.
An actual model call is never automatically repeated. Existing lane activities
hold the visible reasons and a durable marker written before judging starts,
so an interrupted worker cannot silently repeat an uncertain inference delivery.
Reasons appear with results; no migration is required. Commentary
remains incidental and does not retry.

Native PHP fixtures exercise discovery, caching, privacy, pricing and policy.
They also compare a committed browser golden with actual Laravel/Inertia props.
After the real client/SSR build, `npm run test:models-ui` drives that production
client in desktop and mobile Chromium against the generated props. Every
request, including Save and Refresh, is intercepted. It checks withdrawn and
new models, controlled Fancy inputs, explicit withdrawn-model removal,
outage/save/recovery, long-ID saves, dates, lifecycle badges, prices, stale/unavailable notices,
untrusted text and horizontal overflow, and records screenshots plus build,
source and lock hashes. These CI controls make no provider calls and do not
establish live account availability or inference readiness.

Provider panels start collapsed, with model-bearing providers first. Priced or
selected models appear before the expandable count of other unknown prices.
The ID filter and provider jump navigate without saving or changing permissions;
unconfigured providers use compact rows, while failures and unavailable saved
choices stay expanded. The native synthetic scale fixture contains 142 OpenAI
rows built from the public price entries and invented future IDs, rather than
an operator's live account roster. Both browser sizes exercise it along with
the normal, refreshed, outage, recovery, stale and authentication states.

### First-party prerelease ranges in the local Lab

The Lab is never deployed or published. Its `agent-integrations` constraint is
`>=0.52.0 <1.0.0`: the locked lane keeps the reviewed distribution (currently
0.53.0), while the current lane installs the newest first-party 0.x release and
actually exercises it. A new minor is a reason to test its behavior, rather
than repeatedly changing a caret range before the preceding PR can land.
Version 1.0 remains outside this range and requires a separate review.

This policy is specific to the first-party package with observed release churn.
Other package constraints, every third-party constraint and the uncached
currency gate are unchanged. The reviewed lock stays reproducible; broad
dogfood ranges are not a recommendation for deployed applications or published
packages.

`npm run test:ui` runs the provider display checks and
`scripts/test-human-plus-bridge.mjs` in both frontend lanes. The native controls
exercise the Lab's one-form call shape, controlled writes and refusal, human
confirmation, owner isolation, replacement/disposal, deferred host acceptance,
late rejection and partial bulk writes. They allow additional tools while
requiring the existing call shapes to keep working. A behavior regression fails
CI even when package currency passes. These are synthetic, in-process controls
without a relay, credentials or billable calls; they do not establish external
session acceptance or coverage of every upstream API.

### Browser installation and cache evidence

The frontend job has a 20-minute bound. The earlier 10-minute bound expired
twice during Chromium's system dependency installation; one measured attempt
spent 9 minutes 42 seconds there and never reached the browser, render or build
checks. Ubuntu reported 25.9 MB of package archives and 79.5 MB of installed
space. The added margin covers installation plus the existing assertions.

Chromium system dependencies install in their own step with a 12-minute bound
and a clear failure message. Browser installation follows separately with a
5-minute bound. Both must succeed before the unchanged browser tests, renderer
control and builds run. The browser cache does not replace system dependency
installation or skip any assertion.

The Azure apt mirror also stalled during a warm-cache control, so this step
selects Ubuntu's upstream archive in the disposable runner's mirror list.
Ubuntu channels and package signature verification stay enabled. A missing
expected mirror list fails explicitly so a runner-image change is investigated.

Only `~/.cache/ms-playwright` is cached, under an exact operating-system and
Playwright-version key with no fallback keys. The version comes from the lane's
resolved `package-lock.json` and must match the installed `playwright-core`.
`playwright-cache-evidence.json` records that version and the exact cache-hit
result alongside the existing lock and render artifacts. Validate the cache
with successful cold-cache and warm-cache runs on the same reviewed commit;
a restored cache alone does not prove browser behavior.

### Every committed lock is audited

`npm run audit:locks` discovers every Git-tracked `package-lock.json` and
`composer.lock`, including nested runtimes. It audits lock data without
installing packages or running lifecycle scripts, checks dev dependencies too,
and records each path, SHA256 and result in `lock-audit-evidence.json`. The PHP
CI lanes run it before installation. Empty inventory, missing tools, invalid
evidence and registry failures fail the gate; a failing lock does not skip the
remaining audits. All severities are checked. Current-resolution lanes retain
their separate audits after resolving updates.

The isolated Remotion runtime uses exact Remotion 4.0.540 packages. Its published
Studio parent resolves patched Zod 4.6.7, so the temporary exception for
`GHSA-mrhx-c7xc-m3w5` has been deleted from `scripts/lock-audit-exceptions.json`.
The lock contains the patched package and its audit passes without a waiver,
dependency override or downgrade. This resolves the dated November 8 re-review
early: the guard detected the newly patched parent on November 2. Every-lock
audits remain mandatory, and the existing registry/expiry controls continue to
test that a future exception cannot outlive a published fix.

Both frontend CI lanes install the isolated renderer with `--ignore-scripts`,
render an owned 30-frame control, and inspect its H.264 artifact with ffprobe.
This needs no database, provider credentials or model requests. The receipt
records the artifact and runtime-lock hashes. Locally, run
`npm ci --prefix tools/remotion-runtime --ignore-scripts` followed by
`npm run test:remotion-runtime`. On Windows, `PRISM_AUDIT_COMPOSER_PHAR` may name
the absolute Composer PHAR and `PRISM_AUDIT_PHP_BINARY` its PHP executable for
the every-lock audit when Composer is not on PATH.

### PHP ACP source and release coverage

PHP ACP is public at `Particle-Academy/prism-acp`, with the first stable release
`v0.1.1` at `a3edf05d07b0cbc81b8cbb1d714fd4cc71dd04dc`.
The reviewed version, tag and source SHA live in `scripts/acp-release.json`;
dependency switching, artifact verification and the browser fixture read that
same file. The exact Composer constraint and lock must match it.
`acp.yml` runs Windows consumer lanes against both current sibling source and
the actual release archive resolved through Packagist. It verifies the release's
source SHA and dist installation. The fake providers belong to the Lab's tests,
since release archives exclude package tests. No model calls or real tools run.
The normal local install uses the exact released archive; the noncanonical ACP
path entry only inventories the source sibling. The source lane explicitly
selects its development alias.
Both lanes exercise both providers, resume, model refusal, Claude wildcard tool
denial (including a future tool), Codex approval denial, malformed native config,
duplicate delivery and verified process cleanup. Portable transport tests check
effective MCP isolation and fixed policy; browser controls check provider/model
selection, Continue and escaped output. Ubuntu runs portable checks;
Windows-only process tests are named skips there. POSIX containment remains open.

The ACP page defaults to Claude/haiku, without changing the Lab's global provider
defaults. Codex uses the native account's model unless one is selected. Resume
requires the same provider and a completed source turn. Codex replay is stored
and shown separately from the new answer. Both providers use their native login;
the Lab does not forward API keys. The Overseer worker also runs ACP jobs.

Claude's wildcard hook denies every tool. Codex has a bounded conversation policy:
read-only sandbox, untrusted approval policy, shell/search/apps/subagents disabled,
all effective inherited and explicit MCP servers disabled by ID, and every ACP
permission request declined. Native tools can bypass ACP permission callbacks;
an empty MCP map is deep-merged by Codex and does not disable inherited servers.
The host reads effective configuration on the same connection before thread
start/resume and refuses the session if it cannot verify isolation. This is a
measured policy for the installed CLI, not a universal denial of future tools.

Optional native control (Windows, measured Codex CLI 0.163.0):

```sh
node scripts/native-acp-policy.mjs <php.exe> <codex.exe>
```

It uses owned disposable homes and local model/MCP fixtures, without paid model
calls or user credentials. Harmless shell and auto-approved inherited MCP calls
are positive controls; the identical hidden native invocations must fail under
the Lab policy on both start and resume. Every process tree must be verified
empty. Recheck these controls when upgrading the native CLI. The general ACP
limitations for model-list paging and protocol metadata (G-99/G-100) remain open.

## The improvement loop

State the expected outcome before execution. Preserve the frozen spec and its
digest, independently checkable receipts, explicit dissent, and the human's
review. Failures and inconclusive runs must remain visible. A meaningful
finding becomes a file-first 0L report and a regression control; a deliberate
mutation must show that the control can detect the defect.

The dogfood group covers Consensus collection through review, benchmark
dispatch, proof/receipt validation, scoring, and result presentation. It includes
successful controls alongside refusals: a system broken shut cannot earn a
pass by refusing everything. It is deterministic integration evidence, not an
assessment of the scientific quality of a model's answer. No autonomous process
can claim to incorporate every new RSI research method merely because CI passes.

## Local prerequisites and diagnosis

Use Genie's Processes panel to keep these running:

| Process | Command | Purpose |
|---|---|---|
| TypeScript agent, from prism-ts | `node agent/server.mjs` after `npm run build` | Consensus and port probes |
| Python agent, from prism-py | `python agent/server.py` | Consensus and port probes |
| Overseer worker, from prism-labs | `php artisan queue:work --queue=overseer --sleep=1 --tries=1 --timeout=1800` | Design conversations and Consensus collection |
| Workflow worker | `php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=1800` | PHP benchmark lanes and compaction probes |
| Commentary worker | `php artisan queue:work --queue=commentary --sleep=1 --tries=1 --timeout=120` | Run narration |
| Scoring worker | `php artisan queue:work --queue=scoring --sleep=1 --tries=1 --timeout=180` | Blind rubric scoring |

Leave capacity for the site's own processes. Four PHP web workers plus the
site server and the six processes above require eleven slots. A saved bare
`npm` command fails under Windows CreateProcess; the direct Node command works
on both platforms. Build the TypeScript port before starting its agent and
restart both language agents after changing their loaded code. Restart queue
workers after changing application code. Set the database queue's retry delay
longer than its longest job timeout when using multiple workers.

Consensus and Benchmarks show a separate, free readiness check of both language
agents. It distinguishes stopped agents, missing credentials, stale loaded code,
and absent capabilities. Readiness is not a successful live provider call.
Consensus submission queues work immediately and polls while collecting. Its
responses retain failure reasons; empty answers and semantic refusals do not
count as opinions. A failed job closes the run without inventing a consensus.

**TypeScript and Python benchmark lanes remain unsupported (G-10).** Their
agents have no lane executor. Starting the processes or passing conformance
does not close that gap. The preflight must keep refusing such lanes until the
shared execution contract and both runners exist. PHP benchmark lanes remain
the positive control; their workers and provider configuration must be healthy.

## Reproduce the gates

```sh
composer test
vendor/bin/phpunit --group dogfood --fail-on-empty-test-suite
node --test scripts/dogfood-dependencies.test.mjs
npx tsc --noEmit
npm run test:ui
npx playwright install chromium
npm run test:dogfood-ui
npm run build
composer audit
npm audit --audit-level=high
node scripts/dogfood-dependencies.mjs --currency
node scripts/dogfood-dependencies.mjs --composer-currency
node scripts/dogfood-dependencies.mjs
```

Run the last command from the usual envelope layout with all siblings present.
It writes `dogfood-evidence.json`. The browser controls intercept all network
requests and use fixtures; they do not start a server, touch the local database,
or spend provider tokens.
