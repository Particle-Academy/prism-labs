# AGENTS.md — particle-academy/prism-labs

The Prism Lab: a local-only testbed that exercises Prism and its satellites
against **real provider APIs**, and the home of the agent team.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## The remit is wide. Do not narrow it.

This has already been got wrong once, so it is stated first and plainly.

The Lab is **not** a cross-language conformance checker that happens to have a
chat window. It is the testing suite for the whole ecosystem — every satellite,
every port, live providers including Perplexity — **and** the place the team
collaborates on AI research and best practice, which is the part that exists to
build and hold competitive advantage.

The first version of the coordinator's prompt described its job as port
conformance. It then correctly refused research questions, because it had been
told that was not its job. If you are scoping a new capability here and find
yourself writing a narrow remit because it is easier to evaluate, that is the
same mistake with a different name.

## Local only. This is a hard constraint.

Real provider keys live here, and the Lab spends real money. **It is never
deployed.** There is no staging copy, no preview URL, no CI job that runs it
against live providers.

The language agents listen on **loopback** by default, and the comment in
`config/team.php` says why in one line worth repeating: an agent that can run a
test suite and spend tokens is remote code execution wearing a friendly name.
Binding one to a non-loopback interface is not a configuration tweak.

`prism-sandbox` is the deployed, public, credential-light app. If a thing you
are building needs to be *seen* by someone, it belongs there, not here.

## The team

| | |
|---|---|
| `app/Team/Coordinator.php` | Prism.php — reasons about the ecosystem, so it gets the stronger model |
| `app/Team/LanguageAgent.php` | one per port, reached over MCP on loopback |
| `app/Team/AgentRoster.php` | who exists, including ports marked PLANNED |
| `app/Research/Researcher.php` | the research surface — `search_web`, `research` |
| `app/Learnings/` | 0L reports |
| `app/Conformance/` | corpus runs and per-case comparison |
| `config/team.php` | providers, models, endpoints, step bounds |

**`max_steps` is a spending bound, not a tuning knob.** Every coordinator step
can call a teammate, and a teammate call is billable in that teammate's own
account. An unbounded coordinator spends other people's budgets.

Models default to Anthropic throughout. That is deliberate and set in
`config/team.php`; do not reintroduce an OpenAI default as a fallback.

## 0L reports

The format is decision
[0017](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0017-the-0l-report-format.md),
and it is now cited by estates outside this one — so change the decision, in
prism-parity, before you change the writer here.

Two rules from it that are easy to break locally:

- **The file is authoritative and is written first.** The database row is the
  feed a board renders. A row pointing at a file that does not exist is worse
  than no row.
- **The next id comes from the files, not the table.** A rebuilt database must
  not reissue an id that already exists on disk.

"Why it matters to the ecosystem" is enforced in code rather than trusted to the
filer. Keep it that way — it is the section an agent in a hurry leaves blank,
and an empty heading reads as *nobody thought about this*.

## What the conformance view does not prove

The Lab runs the shared corpus in each language lane and compares case by case.
**That detects disagreement, not correctness.** If every port is identically
wrong — the likely failure, since one was ported from another — the comparison
agrees with itself and renders green.

"Languages agree" is a true statement about the ports and not a claim about the
behaviour. Do not label it in the UI as though it were the second thing.

The counter is in the shared guide: state the expected value before the run and
review against that. The corpus is where that belongs, and it is not built yet.

## Task lists are dogfooded adversarially, and the order matters

`/lab/tasks` exercises `prism-harness` task lists as a consumer. Two things
about it are easy to break by accident:

- **The board asks security properties, not the happy path.** Seeding two tasks
  and claiming them is green on every version of the package including the ones
  with a hole in. If you add a lane, ask what can still be invoked after the
  guard — a lapsed lease, a release from the wrong worker, a worker id one
  codepoint off. `an-authorized-holder-can-close-its-own` is a POSITIVE CONTROL
  and must stay: every other lane passes when the completion tool refuses, so
  without it a tool broken shut scores a perfect board.
- **The live lane must run AFTER the board, in the same request.** It registers
  `complete_task` on `ToolRegistry`, which is a process-wide singleton with no
  unregister and no per-session scoping — so from that point on,
  `resolve(['*'])` answers differently for the rest of the request and the
  registered-nowhere lane would report red for something this application never
  did. `AgentTaskListTest` pins this by registering the tool and requiring that
  lane to fail.

`complete_task` is registered **nowhere else**, and that is the alignment
decision rather than an oversight. Do not add it to a mode's `['*']` toolset.

## Gates

```sh
composer test        # config:clear, then artisan test
npm run build        # CI builds the front end; a type error here fails the job
```

CI runs `tests`, `formatting` and `build`. There is no live-provider job and
there should not be one.
