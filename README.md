# Prism Lab

A local-only testbed that exercises [Prism](https://github.com/Particle-Academy/prism)
and its satellite packages against **real provider APIs**.

Not a demo, and not a documentation site. This app exists to find out whether
a release actually works before anyone is asked to depend on it.

> **Working on this package?** Read **[`AGENTS.md`](AGENTS.md)** first — the boundary
> this package has to hold, the gates that must be green, and the traps that have
> already caught someone.
> `@link AGENTS.md`

## Why it is a separate application

The Lab used to live inside `prism-sandbox`, which is also the public docs
site — and that site auto-deploys on push to `main`. So an app holding
credentials for sixteen providers and running billable generations shared a
deployment with a site that serves the internet.

Nothing had gone wrong. The arrangement was simply one mistake away from
going wrong in a way that could not be undone, and the two things want
opposite defaults: a docs site wants to be public, cached and stable; a
testbed wants to be private, uncached, and pointed at whatever is least
proven.

## What it tests against

The sibling working trees, through Composer path repositories. The inline
release aliases in `composer.json` satisfy dependency constraints; they do not
turn those working trees into released archives. Installing them proves only
that dependencies resolve. The tests and probes exercise their behavior.

## Surfaces

| Page | What it is for |
|---|---|
| **Chat** | Drive a generation against any configured provider. Text, tool calling, and web research through `prism-perplexity` — so a request can cross two vendors and the core shuttle in one trip. |
| **Tests** | Run the feature suite against live APIs, not fakes. A recorded fixture proves the parser; only a real call proves the provider. |
| **Threads** | What `prism-harness` actually stored — rebuilt through its own mapper, showing the value objects a provider would receive rather than the JSON on disk. |
| **Benchmarks** | Latency and cost across providers and models, tracked over time. |
| **Provider probes** | At `/lab/provider-probes`: unsuccessful Perplexity run diagnostics, guarded URL fetches and refusals, and cache stability hints with provider-reported token evidence. |

Provider probes run only when submitted. Research makes a Perplexity call;
the cache probe makes two Anthropic calls with the same stable reference and
different volatile questions, each capped at 128 output tokens. The cache
read/write counts are the evidence: a successful response with zero or missing
counts does not demonstrate caching. Short references may be below the model's
caching threshold.

The guarded fetch preset is a metadata address that must be refused before
any HTTP request. You can also enter a public URL for a positive control.
The surface displays the package's refusal code, or a bounded text preview of
the downloaded content. Public-address checking is not a network sandbox.

The research form and Perplexity text/streaming cases in the provider matrix
show unsuccessful runs' code, ID, stop reason, partial output, citations and
usage. Partial provider content is escaped text, is never a completed answer,
and is excluded from exception reporting and benchmark history. These surfaces
remain behind the Lab's local-environment and loopback-peer guard.

`composer test` drives the real package APIs with fake HTTP and DNS responses,
including refusals and success controls. `npm run test:ui` renders the shared
result components and checks diagnostics, escaping and token evidence. Neither
uses live provider calls; live calls require the operator's explicit action.

## Running it

CI, dependency currency, the evidence loop, and required local workers are
documented in [Dogfood evidence and CI](docs/dogfood-ci.md).

The Lab owns its database name through `LAB_DB_DATABASE`. Workspace
`DB_DATABASE` and `DB_URL` cannot select it. SQLite defaults to this app's
`database/database.sqlite`; server adapters default to `prism_labs`. Create a
dedicated database before migrating, and use a database account restricted to
that database. Server host, port and credentials still use the `DB_*` fields.
If the Sandbox uses a different name or SQLite location from the workspace
defaults, declare `LAB_SANDBOX_DB_DATABASE` or `LAB_SANDBOX_SQLITE_DATABASE`.
The Lab refuses a shared identity before application providers register.

An old cached configuration must be removed from `bootstrap/cache/config.php`
before the first boot with these settings, then regenerated with the dedicated
namespace. Tests force anonymous in-memory SQLite and reject file or server
connections, including late named and on-demand connections. Tests do not
require a running database service.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

Then serve it. Every provider key is optional — the Lab reports an
unconfigured provider and names the variable to set, rather than hiding it.

Home and `/lab/preflight` report queue worker heartbeats, actual database
identity/setup, provider key presence and core's observed main checks. See
[session preflight](docs/session-preflight.md) for the refusal states and
verification limits. Readiness does not grant approval or launch authority.

## Local only, by construction

Routes sit behind `EnsurePrismLabIsLocal`, which checks the **raw socket
peer** rather than `$request->ip()`. That distinction matters: an app that
trusts proxies will honour a client-supplied forwarding header, and `ip()`
would then accept whatever an attacker claimed. There is also an
`app()->environment('local')` gate around the whole route group.

Both stay even though this app is never deployed. They are what keeps that
true by construction rather than by intention.

## Change council

The owner-only ledger is at `/council`; its single email must belong to the same
configured `LAB_OWNER_USER_ID`. Council 0.7.1 records that verified numeric ID
through the native actor hook; historical email actors remain unchanged.
All four pages and native APIs share the same owner boundary. Prism's census
scope is configured per ecosystem; fixture checks do not claim a live census.
See [setup, clerk procedure and operational holds](docs/change-council.md).
