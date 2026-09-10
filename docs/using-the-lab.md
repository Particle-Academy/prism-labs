# Using the Lab

The Lab runs at `https://plabs.gen` and only in `local`. Everything below spends
real money against live providers — there is no mock mode, on purpose, because
the questions the Lab asks (does compaction lose something? do three languages
agree?) have no meaning against a fake.

## Start here: the Overseer

The **Overseer** is the agent in the bottom-right of every screen. It is the way
you use the Lab, not a help widget bolted onto it.

Ask it in ordinary language — *"I want to know whether our tool reservation
survives a compacted window"* — and it will shape the question into a
specification you can review. It can read the published Prism documentation and
this Lab's own (see [agent-guide.md](agent-guide.md)), so you can also just ask
it how something works.

Open it from the launcher, or from **Open Overseer** on the Benchmarks page.

## The screens

| Screen | What it is for |
|---|---|
| **Cockpit** `/lab` | Active runs, today's tokens and cost, the operations ledger |
| **Benchmarks** `/lab/benchmarks` | Design a benchmark, review a spec, launch it, read the run room, and the compaction probes |
| **Models** `/lab/models` | Which provider and model each role uses |
| **Consensus** `/lab/consensus` | Ask several lanes the same question and compare |
| **Evidence** `/lab/evidence` | 0L learnings — findings recorded from runs, and sending them to an agent |
| **Diagnostics** `/lab/diagnostics` | Provider latency history and other non-benchmark measurements |
| **Team** `/lab/team` | The live per-family ecosystem probe, green only when both languages agree |
| **Threads / Telemetry / Tests / Tasks** | The durable conversation record, spans, the suite, and the task lane |

## Designing and running a benchmark

The flow is deliberately gated, and each gate exists because skipping it
produced a result somebody then believed.

1. **Understand the question.** Talk to the Overseer. What would change your
   mind? A benchmark whose result changes nothing is not worth its tokens.
2. **Define evidence.** What counts as proof, decided *before* the run.
3. **Design the rubric and budgets.** Tokens, spend, elapsed time, turn ceiling.
4. **Propose a draft.** The Overseer writes a revisioned specification.
5. **Approve it.** Approval **freezes the digest**. A spec cannot launch until a
   human approves it, and an approved spec cannot change without a new revision.
6. **Launch.** Each lane gets the same spec, a randomised identity, and an
   isolated workspace.
7. **Read the run room.** Per-lane proof, files the lane actually wrote, and a
   commentary track.

**Nothing launches from a draft.** The frozen digest is the whole point: a
result is only meaningful if you can say exactly what was asked.

## The compaction probes

Three buttons on the Benchmarks page, each spending real tokens. All are
**queued** — you press, you get a message saying it is queued, and the result
appears when you reload in a couple of minutes. See
[benchmarks-and-probes.md](benchmarks-and-probes.md) for how to read the
verdicts.

## Things that will confuse you once

- **A queued probe shows nothing immediately.** That is correct. You get a flash
  message; the row appears on reload. A probe drives a full multi-turn
  conversation against a live provider.
- **The Lab serialises requests.** It is served single-threaded, so while
  something slow is running, everything else waits. If a page feels frozen,
  something is probably running.
- **Approvals are real.** `workspace_delete` in a benchmark lane needs a human.
  Nothing else in a lane does, because gating everything is how a gate stops
  being read.
- **"Inconclusive" is a result, not a failure.** The probes report
  `inconclusive-*` verdicts when a run proved nothing, rather than passing.
  Treat those as "ask again differently", not "try until green".
