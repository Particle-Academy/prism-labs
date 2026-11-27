"""The section-2 metrics of the G-393 plan, with the delta against the neutral arm.

A trial record is a dict:

    benchmark   "terminal_bench" | "tau2"
    arm         "prism" | "terminus-2" | "llm_agent"
    task_id, trial
    status      "solved" | "failed" | "cap_stopped" | "error" | "not_run"
    reward      float | None
    tokens      {fresh_input, cache_write, cache_read, output}
    cost_usd, steps, wall_seconds

Rules that decide the headline numbers, stated where they are applied:

- $ per solved task is TOTAL spend over tasks solved, so failed and stopped
  attempts count against it. With nothing solved it is None, never 0 or inf.
- cap_stopped is an attempt that was not solved. It is never solved, whatever
  the grader would have said about a half-finished container.
- not_run (the arm's cap was spent before the task started) is not an attempt.
- The delta compares the arms on the SAME task ids only: the tasks both arms
  attempted. A delta over different task sets measures the sets.
"""

from __future__ import annotations

import math
from collections import defaultdict
from typing import Any, Iterable

NEUTRAL = {"terminal_bench": "terminus-2", "tau2": "llm_agent"}
ATTEMPTED = {"solved", "failed", "cap_stopped", "error"}
TOKEN_KEYS = ("fresh_input", "cache_write", "cache_read", "output")


def wilson(successes: int, n: int, z: float = 1.96) -> tuple[float, float] | None:
    if n == 0:
        return None
    p = successes / n
    denom = 1 + z * z / n
    centre = (p + z * z / (2 * n)) / denom
    half = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / denom
    return (round(max(0.0, centre - half), 4), round(min(1.0, centre + half), 4))


def pass_hat_k(trials: list[dict[str, Any]]) -> dict[int, float]:
    """tau2's pass^k: per task C(successes, k) / C(trials, k), averaged over tasks."""
    by_task: dict[str, list[bool]] = defaultdict(list)
    for t in trials:
        if t["status"] in ATTEMPTED:
            by_task[t["task_id"]].append(t["status"] == "solved")
    if not by_task:
        return {}
    k_max = min(len(v) for v in by_task.values())
    out = {}
    for k in range(1, k_max + 1):
        out[k] = round(sum(math.comb(sum(v), k) / math.comb(len(v), k) for v in by_task.values()) / len(by_task), 4)
    return out


def summarise(trials: Iterable[dict[str, Any]]) -> dict[str, Any]:
    trials = list(trials)
    attempted = [t for t in trials if t["status"] in ATTEMPTED]
    solved = sum(1 for t in attempted if t["status"] == "solved")
    cost = sum(float(t.get("cost_usd") or 0.0) for t in trials)
    tokens = {k: sum(int((t.get("tokens") or {}).get(k, 0)) for t in attempted) for k in TOKEN_KEYS}
    all_input = tokens["fresh_input"] + tokens["cache_write"] + tokens["cache_read"]
    n = len(attempted)
    tasks = sorted({t["task_id"] for t in attempted})
    return {
        "attempts": n,
        "tasks": len(tasks),
        "solved": solved,
        "score": round(solved / n, 4) if n else None,
        "score_ci95": wilson(solved, n),
        "cost_usd": round(cost, 6),
        "usd_per_solved": round(cost / solved, 6) if solved else None,
        "usd_per_attempt": round(cost / n, 6) if n else None,
        "tokens": tokens,
        "tokens_per_attempt": {k: round(v / n, 1) for k, v in tokens.items()} if n else None,
        "cache_read_share": round(tokens["cache_read"] / all_input, 4) if all_input else None,
        "steps_per_attempt": round(sum(int(t.get("steps") or 0) for t in attempted) / n, 2) if n else None,
        "wall_seconds_per_attempt": round(sum(float(t.get("wall_seconds") or 0) for t in attempted) / n, 1) if n else None,
        "cap_stopped": sum(1 for t in trials if t["status"] == "cap_stopped"),
        "errors": sum(1 for t in trials if t["status"] == "error"),
        "not_run": sum(1 for t in trials if t["status"] == "not_run"),
    }


def _delta(a: Any, b: Any) -> Any:
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return round(a - b, 6)
    return None


def compare(trials: Iterable[dict[str, Any]]) -> dict[str, Any]:
    """Per benchmark: every arm's summary, and each non-neutral arm's delta vs neutral on shared tasks."""
    trials = list(trials)
    out: dict[str, Any] = {}
    for bench in sorted({t["benchmark"] for t in trials}):
        rows = [t for t in trials if t["benchmark"] == bench]
        by_arm: dict[str, list[dict[str, Any]]] = defaultdict(list)
        for t in rows:
            by_arm[t["arm"]].append(t)
        result: dict[str, Any] = {"arms": {arm: summarise(ts) for arm, ts in sorted(by_arm.items())}, "deltas": {}}
        if bench == "tau2":
            for arm, ts in by_arm.items():
                result["arms"][arm]["pass_hat_k"] = pass_hat_k(ts)
        neutral = NEUTRAL.get(bench)
        if neutral in by_arm:
            for arm, ts in by_arm.items():
                if arm == neutral:
                    continue
                shared = ({t["task_id"] for t in ts if t["status"] in ATTEMPTED}
                          & {t["task_id"] for t in by_arm[neutral] if t["status"] in ATTEMPTED})
                mine = summarise(t for t in ts if t["task_id"] in shared)
                theirs = summarise(t for t in by_arm[neutral] if t["task_id"] in shared)
                keys = ("score", "usd_per_solved", "usd_per_attempt", "cache_read_share", "steps_per_attempt", "wall_seconds_per_attempt")
                delta = {k: _delta(mine[k], theirs[k]) for k in keys}
                if mine["tokens_per_attempt"] and theirs["tokens_per_attempt"]:
                    delta["tokens_per_attempt"] = {k: _delta(mine["tokens_per_attempt"][k], theirs["tokens_per_attempt"][k]) for k in TOKEN_KEYS}
                result["deltas"][f"{arm}_vs_{neutral}"] = {"shared_tasks": sorted(shared), "arm": mine, "neutral": theirs, "delta": delta}
        out[bench] = result
    return out
