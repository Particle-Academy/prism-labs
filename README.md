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

## Local only, by construction

Routes sit behind `EnsurePrismLabIsLocal`, which checks the **raw socket
peer** rather than `$request->ip()`. That distinction matters: an app that
trusts proxies will honour a client-supplied forwarding header, and `ip()`
would then accept whatever an attacker claimed. There is also an
`app()->environment('local')` gate around the whole route group.

Both stay even though this app is never deployed. They are what keeps that
true by construction rather than by intention.
