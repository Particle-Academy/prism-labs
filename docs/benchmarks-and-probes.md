# Benchmarks and probes

Two different things live on the Benchmarks page.

A **benchmark** is designed with the Overseer, frozen by human approval, and run
across lanes. A **probe** is a fixed experiment in this repository, run by a
button, that asks one question about a guarantee.

## The three compaction probes

All three drive real multi-turn conversations against a live provider, because
compaction only happens on a real transcript. All three are queued.

### Reservation — does a guarantee hold while the window shrinks?

`CompactionReservationProbe`. A reserved tool (`terminal_confirm`) is offered
across a long loop with `clear_tool_uses` on. Compaction makes the agent forget
it already asked, so it asks again — and **every** ask must be refused.

One execution is a failure, so the verdict is not a rate. `attempts` and
`tool_uses_cleared` are shown beside it because "held" means nothing without
them: a run where the model never asked, or where nothing was ever cleared,
proved nothing.

### Recall — is an evicted turn still reachable?

`CompactionRecallProbe`. An invoice number is planted in turn one, padded out of
the window, and asked for back. A **control arm with no lookup must fail** — if
it answers too, the probe is not measuring recall.

Verdicts, in the order they are checked:

| Verdict | Means |
|---|---|
| `inconclusive-never-evicted` | The fact never left the window. Nothing was tested |
| `RECALL FAILED` | It looked and could not recover it. A real failure |
| `inconclusive-agent-never-looked` | It answered wrong without using the tool |
| `summary carried the fact` | Under a summariser, the summary kept it — recall was not needed |
| `inconclusive-control-also-answered` | The control answered. The experiment is not isolating recall |
| `inconclusive-answered-without-looking` | Right answer, tool never called. Not evidence |
| `recall works` | The fact left the window, and only the arm that could look it up answered |

### Summary loss — what does a summariser actually drop?

`SummaryLossProbe`. The recall probe plants an *identifier*, which
`SummarisingCompaction`'s prompt explicitly protects ("keep names, numbers,
identifiers"), so it kept it on every run and never reached the recall branch.

This one plants a **reason mentioned in passing** — the *why* behind an ordinary
decision, never flagged as important, with a plausible wrong answer waiting. It
also records whether the arm without recall *invented* a reason rather than
saying it could not find one. That distinction is the finding: "I cannot find
it" is safe degradation; a confident wrong answer is not.

It checks the **word budget first**, because that explains everything below it.
A summary that did not compress cannot have lost anything.

## How to read a verdict

**`inconclusive` is not "try again until green".** It means the run did not
exercise the thing it names. Change the setup, not the threshold.

Every probe reports its guards beside the verdict — did the fact leave the
window, was anything stored, did the agent look, how long was the summary. Read
those before the verdict. A green result whose guards say nothing happened is
the failure this Lab exists to catch, not a pass.

## What these probes have taught us

Kept here because each cost a run to learn, and each is a trap that looks like a
result.

- **A benchmark can be vacuous and green.** A compaction trigger set at 30,000
  tokens against a 10,642-token peak never fired. The probe passed. It measured
  nothing.
- **A guard can be inverted and still pass.** The summary-loss probe required
  the planted nuance to be absent from *every* window. Compaction first fires
  when the planted turn is nearly all there is to summarise, so the early
  summaries always carry it — a run where the detail vanished early would have
  reported the summariser kept it throughout.
- **A knob can be inert.** `--padding` above 8 silently produced eight turns,
  because the topic list was fixed-length and sliced. Runs asking for more
  rewrites got the same eight.
- **A probe that judges something without showing it will mislead you.** Four
  runs reported a faithful summariser; printing the summary showed a stated
  15-word budget coming back at 92 and 346 words. Nothing was lost because
  nothing was compressed.
- **A distractor inside the kept window suppresses recall rather than testing
  it.** Decoy references placed in padding turns sit in the window the model
  keeps, so it sees plausible codes and stops calling the tool.

The pattern in all five: the probe reported on a mechanism it had not actually
exercised. Check the guards first.
