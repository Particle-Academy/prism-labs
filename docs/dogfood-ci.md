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
- The frontend installs the npm lock, then the current lane resolves allowed
  updates. It also compares every direct first-party npm package with the
  uncached registry. A release outside the current constraint fails currency
  rather than being silently ignored. Dependabot groups first-party npm
  upgrades into reviewable pull requests.
- Both run their dependency audit. PHP runs the full suite and the explicit,
  nonempty `dogfood` group. The frontend typechecks, checks provider displays,
  runs Chromium against the actual application entry and pages, and builds
  both client and SSR bundles.
- Factcheck uses the current shared checker and trust rubric from prism-parity.
  The rubric checks the quality of the shared security corpora; it does not
  certify this application's live provider behavior.

PHP artifacts include the Lab SHA, every sibling SHA and dirty state, both lock
hashes, and the resolved Composer lock. Frontend artifacts include its resolved
npm lock. These identify what passed. Path aliases in Composer are constraint
compatibility labels, not proof of the published release installed by a user.
These jobs exercise source checkouts, not downloaded release archives.

### Rollout prerequisite: PHP ACP source

As audited on 2026-10-23, `../prism-acp` is local Git only: no origin remote,
no GitHub repository, and no Packagist release. The unpublished ACP integration
in the local Lab requires it; the published baseline does not. The CI rollout
is based on the published baseline, so those unrelated development commits do
not prevent the current published application from being tested. A clean
runner cannot install the ACP integration yet. Its missing checkout
must fail visibly; removing it from the manifest, substituting a stub, or
skipping its tests would create false coverage. Publish the reviewed PHP ACP
source at its agreed remote, or supply that source through another reviewed
CI mechanism, before claiming that integration is CI-tested. The ACP plan
also records unfinished POSIX containment and release review.

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
