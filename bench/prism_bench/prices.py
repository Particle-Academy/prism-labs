"""Dated list prices, read from the frozen plan, never guessed.

The plan's prices are checked against the Lab's ModelPrices by a PHP test
(tests/Feature/HarnessBenchPlanTest.php), so the two cannot drift silently.

All figures are USD per million tokens. `fresh` input excludes cache reads and
writes (prism-bench-driver/1 reports it that way, and so does Prism core).
"""

from __future__ import annotations

from typing import Any


def _tier(entry: dict[str, Any], total_input: int) -> dict[str, Any]:
    chosen = entry
    for tier in entry.get("tiers", []):
        if total_input > int(tier["aboveInputTokens"]):
            chosen = {**entry, **tier}
    return chosen


def cost_usd(entry: dict[str, Any], *, fresh: int, cache_write: int, cache_read: int, output: int) -> float:
    total_input = fresh + cache_write + cache_read
    rate = _tier(entry, total_input)
    write = rate.get("cacheWritePerMTok", rate["inputPerMTok"])
    read = rate.get("cacheReadPerMTok", rate["inputPerMTok"])
    usd = (fresh * rate["inputPerMTok"] + cache_write * write + cache_read * read + output * rate["outputPerMTok"]) / 1e6
    return usd * float(entry.get("multiplier", 1.0))


def conservative_cost_usd(entry: dict[str, Any], *, prompt_including_cache: int, cache_hits: int, output: int,
                          reported_usd: float | None = None) -> float:
    """For agents that report only `prompt` (cache included) and `cache hits`.

    Cache WRITES are not separated in that shape, so every non-hit input token
    is priced at the higher of the input and cache-write rates, and the larger
    of this and any provider- or library-reported figure is charged. An
    over-estimate stops an arm early; an under-estimate spends past its cap.
    """
    uncached = max(0, prompt_including_cache - cache_hits)
    rate = _tier(entry, prompt_including_cache)
    write = max(rate["inputPerMTok"], rate.get("cacheWritePerMTok", rate["inputPerMTok"]))
    read = rate.get("cacheReadPerMTok", rate["inputPerMTok"])
    ours = (uncached * write + cache_hits * read + output * rate["outputPerMTok"]) / 1e6 * float(entry.get("multiplier", 1.0))
    return max(ours, float(reported_usd or 0.0))


def worst_call_usd(entry: dict[str, Any], *, input_bound: int, output_bound: int) -> float:
    """The most one call can cost: every input token at the highest input-side rate of the highest tier."""
    rates = [entry, *entry.get("tiers", [])]
    top_in = max(max(r["inputPerMTok"], r.get("cacheWritePerMTok", 0.0)) for r in rates)
    top_out = max(r["outputPerMTok"] for r in rates)
    return (input_bound * top_in + output_bound * top_out) / 1e6 * float(entry.get("multiplier", 1.0))
