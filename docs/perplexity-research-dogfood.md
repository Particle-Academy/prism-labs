# Perplexity deep research checks

The provider test suite contains eight explicit, costly, local operator cases
under `perplexity.research.*`. They cover completed research, token-limited
incomplete output, genuine failed-run observation, both legacy model request
shapes, the configured long-run path, and date-plus-recency filters on both
models. Page load, CI and the normal non-costly CLI selection never submit them.

Select them on `/lab/tests`, or explicitly run:

```sh
php artisan lab:test perplexity.research.completed perplexity.research.incomplete perplexity.research.failed perplexity.research.shape-deep perplexity.research.shape-pro perplexity.research.long perplexity.research.filters-deep perplexity.research.filters-pro --include-costly
```

Each submission makes one request, with no retry. Completion is capped at 2048
output tokens and six provider steps. Other cases use at most 64 output tokens
and four steps; the incomplete case uses one token and one step. These bounds
limit work; they are not a guaranteed dollar ceiling. Review your provider's
pricing before submitting. Use the Lab's existing provider configuration.

Results expose status, known incomplete reason, presence of identifiers/output/
usage/error details, citation count and timing. They contain no prompts, answer
content, raw provider error or credential values. These probes disable Prism
telemetry for their call and restore its prior state afterwards.

Missing credentials return `SKIPPED: no key`, with `passed=false`. An incomplete
or failed run must actually be observed to pass its case. A completed answer or
HTTP rejection does not substitute for HTTP200 `status=failed`: that coverage
stays `UNMEASURED` until the provider returns a genuine failed run. Unit controls
use documented response shapes and are separate from live evidence.

The long-path case records the configured timeout and whether the call actually
exceeded 60 seconds; a fast accepted call does not measure behavior after 60
seconds. Deep research defaults to 600 seconds in core. Background orchestration
is separate from this synchronous check.

The initial #103 operator run returned HTTP401 for every request. The maintainer
confirmed through the real Lab UI that the account was out of credits. Those
failures are retained; successful acceptance remains unmeasured until credits
are restored and a new operator run is explicitly submitted. No key values belong
in fixtures, test output or bug reports.
