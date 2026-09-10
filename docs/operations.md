# Running the Lab

Everything is managed by Genie. Do not hand-fire dev servers — a manually
started server is invisible to Genie, survives nothing, and drifts from the
ports and environment Genie injects.

## What has to be up

| Piece | Why |
|---|---|
| **Site** `plabs.gen` | The app. `manageSite` |
| **Postgres** | Everything durable. Genie injects the connection into `.env` |
| **Prism Lab Workflow Worker** | Queue `default` — every probe and benchmark lane |
| **PLab Commentary Worker** | Queue `commentary` |
| **PLab Scoring Worker** | Queue `scoring` |
| **prism.ts / prism.py agents** | The language lanes and `/lab/team` |
| **Prism Human+ Relay** | Human+ surfaces |
| **Prism Browser** | Browser capability |

Genie injects the database connection. **Never hand-edit `.env` for it** — the
port is reallocated on restart, so a value pinned by hand goes stale silently
and the app connects to nothing.

## Failure modes that look like other bugs

These are worth knowing because each one presents as something else entirely.

**A worker holds the code it booted with.** Add or change a job class and the
running worker will not see it — the job fails or vanishes. Restart the worker
after changing anything it runs. This has cost hours more than once.

**The site serialises requests.** It is served by a single-threaded
`php artisan serve` (measured: one request 0.6s, four concurrent 2.6s). While
anything slow runs, everything else waits. Symptoms include a progress bar that
vanishes, a button stuck on "Running…", results that never appear, and the
Overseer panel refusing to open. All four can be one blocked request.

**Docker down means Postgres down.** After a machine restart, Docker Desktop may
not be running; the service reports `failed` and every page 500s on a connection
refused. Start Docker, then the service.

**A pushed tag is not a release.** In the packages, the release workflow refuses
a tag whose tests have not already succeeded for that exact commit — so pushing
`main` and the tag together fails the guard, silently, while `git push` reports
success. Wait for tests, then tag, then check `gh release view`.

## Diagnosing "I clicked and nothing happened"

In order, because the cheap checks rule out the common causes:

1. **Is a job queued or running?** `jobs` and `failed_jobs` tables. A queued
   probe with no worker running looks exactly like a dead button.
2. **Is the worker up, and was it restarted since the code changed?**
3. **Did the flash render?** Controllers flash `->with('status', …)`; the shell
   reads both that and `flash.success`. If you added a flash under another key,
   nothing shows.
4. **Is something else blocking the thread?** See above.
5. **Only then look at the browser.** Console and network. A page that renders
   but whose buttons do nothing is usually hydration or a blocked renderer, not
   a server problem.

## Gates

```sh
composer test      # config:clear, then artisan test
npm run build      # CI builds the front end; a type error here fails the job
```

Run both before pushing. The Lab has no phpstan gate; the packages do.
