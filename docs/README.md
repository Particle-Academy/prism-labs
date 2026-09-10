# Prism Lab — internal documentation

**These pages are not published.** They live in this repository, they are read
by the Overseer through its `docs_*` tools, and they deliberately never reach
[prism.gen](https://prism.gen).

That is a decision, not an oversight. The docs site describes the **ecosystem**
— what a package guarantees and how somebody building on Prism should use it.
This describes **the Lab**, which is a testbed for that ecosystem. Publishing it
alongside the packages would present the Lab's internals as a pattern to copy,
and the Lab is explicitly not a pattern: it exists to be exercised, broken, and
rebuilt around whatever the packages turn out to need.

The rule from the envelope README applies here more than anywhere:

> **Prism is not built for prism-labs.** The Lab is a testbed — it exists to
> exercise the packages and prove the ecosystem holds up for whoever else is
> building on it.

## The pages

| Page | What it answers |
|---|---|
| [architecture.md](architecture.md) | How the Lab is built, and which decisions are load-bearing |
| [using-the-lab.md](using-the-lab.md) | How a person drives it, screen by screen |
| [agent-guide.md](agent-guide.md) | How an agent drives it — the Overseer, and the tools it holds |
| [benchmarks-and-probes.md](benchmarks-and-probes.md) | What each probe asks, and how to read a verdict |
| [operations.md](operations.md) | Running it: services, workers, queues, and the failure modes that look like other bugs |

## Where the other documentation is

The published ecosystem docs are a sibling repository, `prism-sandbox`, under
`resources/docs/`. The Overseer reads both: `docs_index`, `docs_read` and
`docs_search` take a `shelf` of either `prism` (published) or `plab` (here).

If you are changing something a developer outside this repo would need to know,
it belongs on the `prism` shelf, not this one.
