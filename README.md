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

Prereleases, deliberately. The first-party packages are constrained with a
prerelease floor:

```
particle-academy/prism                >=0.115.0-alpha <1.0
particle-academy/prism-harness        >=0.1.0-alpha <1.0
particle-academy/prism-perplexity     >=0.1.0-alpha <1.0
particle-academy/prism-opentelemetry  >=0.1.0-alpha <1.0
```

Composer accepts a prerelease only when the constraint's own floor is one —
`minimum-stability: dev` alone does nothing while `prefer-stable` is on. The
nav shows the installed Prism version, because a result you cannot attribute
to a version is a result you cannot act on.

## The four surfaces

| Page | What it is for |
|---|---|
| **Chat** | Drive a generation against any configured provider. Text, tool calling, and web research through `prism-perplexity` — so a request can cross two vendors and the core shuttle in one trip. |
| **Tests** | Run the feature suite against live APIs, not fakes. A recorded fixture proves the parser; only a real call proves the provider. |
| **Threads** | What `prism-harness` actually stored — rebuilt through its own mapper, showing the value objects a provider would receive rather than the JSON on disk. |
| **Benchmarks** | Latency and cost across providers and models, tracked over time. |

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
