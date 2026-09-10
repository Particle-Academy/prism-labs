# How the Lab is built

A Laravel + Inertia + React application that runs **only in `local`**. Both
service providers return early otherwise, so a Lab accidentally deployed serves
nothing rather than exposing an agent that can spend money and write files.

```
routes/web.php          every /lab route, wrapped in EnsurePrismLabIsLocal
app/Http/Controllers/   thin — validate, dispatch, redirect
app/Benchmarks/         the probes and the benchmark pipeline
app/Docs/               the Overseer's reading (see agent-guide.md)
app/Jobs/               anything that outlives a request
resources/js/pages/Lab/ one page component per screen
resources/prompts/      system prompts, loaded by PromptFile
config/prism-harness.php  the modes — this is where agent behaviour is decided
```

## The Lab consumes the packages; it does not extend them

Everything the agent does goes through `prism-harness`: sessions, threads,
modes, tools, approvals. When a Lab screen needs something a package cannot do,
the answer is a change in the package or a thing that lives only in the Lab —
never a special case inside the package for the Lab's benefit.

This is the rule that gets bent most often, and it is bent in a way that always
sounds reasonable. See the envelope README's "Who this ecosystem is for".

## Modes are where behaviour is decided

`config/prism-harness.php` defines every mode: a system prompt, a tool
allowlist, and a step ceiling.

| Mode | Holds | Point |
|---|---|---|
| `chat` | `['*']` | The Overseer. Everything the Lab can do |
| `verifier` | search, research, fact-check — **not** filing | A verifier that can file its own finding is a second author, not a check |
| `research` | outward-facing lookup | |
| `scoring` | judging a run | |
| `commentary` | narrating a run | |
| `tasks` | `['complete_task']` only | Named explicitly rather than `['*']`: a worker offered every Lab tool could research, file a 0L or write to a workspace on the way to a task it cannot close |
| `benchmark` | lane work, `workspace_delete` behind approval | The one irreversible action needs a human |

**A narrow allowlist is the design, not caution.** The Lab's whole thesis is
that agreement is not verification, and a mode that can do everything cannot be
a check on a mode that can do everything.

## Durable by construction

Sessions are resolved per request and never held. State lives in the database,
so a fresh worker rebuilds the same session, and an approval outlives the
request that asked for it. That is `prism-harness`'s core property and the Lab
is its consumer, not its implementation.

Tables that matter: `learnings` (0L findings), `benchmark_*` (specs, runs,
lanes, scores, commentary), `compaction_probe_runs` and `evicted_messages` (the
probes), `lab_settings`.

## Anything slow is queued, and this is load-bearing

The Lab is served by a **single-threaded** `php artisan serve`. Measured: one
request 0.6s, four concurrent 2.6s — they serialise.

So a long request does not merely make its own button slow, **it freezes the
entire Lab**. That is not theoretical: the reservation probe ran inline for
minutes, and the symptoms looked like four separate bugs — a progress bar that
vanished, a button stuck on "Running…", no result appearing, and a chat panel
that would not open. One blocked thread.

Every probe and every benchmark lane is therefore a queued job. If you add
something that talks to a provider, it is queued. There are tests that fail if a
probe is called directly from a controller.

## Front end

Inertia, one page component per screen, `LabShell` wrapping all of them.

`LabShell` renders flash messages and the Overseer launcher. One thing to know:
controllers flash with `->with('status', …)`, and the shell reads **both**
`flash.success` and the top-level `status` prop — `status` is what Laravel's
Fortify convention sets, the Lab adopted the key, and for a long time nothing
rendered it. Every success message in the app was silently dropped. If you add a
flash, either key works now; check it appears.
