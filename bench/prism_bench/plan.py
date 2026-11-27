"""The frozen plan, its digest, the owner's approval, and the estimate-vs-cap gate.

Nothing calls a provider until `check_dispatch` has passed. It refuses when:

- the approval file for the FTQ id is not on the checked-out tree (main);
- the dispatch inputs (FTQ id, plan digest, cap) differ from the approval;
- the plan's digest differs from the one approved;
- the approval was already used (a results directory or a used marker exists);
- any arm cap is non-positive, the arm caps sum past the approved cap, or the
  estimate exceeds the cap;
- the plan names a retired *-4-* model.
"""

from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any

# Genie FTQ ids: q_<hex> or q_<uuid>. Hex and hyphens only, so an id can never be a path.
FTQ_ID = re.compile(r"^q_[0-9a-f][0-9a-f-]{5,63}$")
RETIRED = re.compile(r"-4-")


class PlanRefused(RuntimeError):
    pass


def canonical(obj: Any) -> bytes:
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def digest(plan: dict[str, Any]) -> str:
    return "sha256:" + hashlib.sha256(canonical(plan)).hexdigest()


def load(path: Path) -> dict[str, Any]:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def arms(plan: dict[str, Any]) -> list[tuple[str, str, float]]:
    """(benchmark, arm, cap) for every arm in the plan."""
    out = []
    for bench in ("terminal_bench", "tau2"):
        for name, arm in plan.get(bench, {}).get("arms", {}).items():
            out.append((bench, name, float(arm["cap_usd"])))
    return out


def estimate(plan: dict[str, Any]) -> dict[str, Any]:
    """Expected and worst-case spend.

    WORST case is the sum of the arm caps: each arm admits a call only when its
    worst case still fits under the arm's remaining cap, so an arm cannot end
    above its cap. EXPECTED uses the plan's per-try figure, which is labelled
    with where it came from; until the calibration has run, it is not measured.
    """
    tb = plan["terminal_bench"]
    per_try = float(tb["estimate"]["per_try_usd"])
    tries = len(tb["tasks"]) * int(tb.get("attempts", 1))
    expected_tb = sum(min(float(a["cap_usd"]), tries * per_try) for a in tb["arms"].values())
    tau = plan["tau2"]
    per_sim = float(tau["estimate"]["per_simulation_usd"])
    sims = int(tau["num_tasks"]) * int(tau["num_trials"])
    expected_tau = sum(min(float(a["cap_usd"]), sims * per_sim) for a in tau["arms"].values())
    worst = sum(cap for _, _, cap in arms(plan))
    return {
        "terminal_bench_tries_per_arm": tries,
        "terminal_bench_tries_affordable_per_arm": {k: int(float(a["cap_usd"]) // per_try) for k, a in tb["arms"].items()},
        "expected_usd": round(expected_tb + expected_tau, 2),
        "worst_case_usd": round(worst, 2),
        "cap_usd": float(plan["cap_usd"]),
        "per_try_source": tb["estimate"]["source"],
        "per_simulation_source": tau["estimate"]["source"],
    }


def validate(plan: dict[str, Any]) -> None:
    for model in _model_ids(plan):
        if RETIRED.search(model):
            raise PlanRefused(f"the plan names a retired *-4-* model: {model}")
    cap = float(plan["cap_usd"])
    if not (cap > 0):
        raise PlanRefused("the plan cap must be positive")
    for bench, arm, arm_cap in arms(plan):
        if not (arm_cap > 0):
            raise PlanRefused(f"{bench}.{arm} needs a positive cap")
    est = estimate(plan)
    if est["worst_case_usd"] > cap + 1e-9:
        raise PlanRefused(f"the arm caps sum to ${est['worst_case_usd']:.2f}, above the approved ${cap:.2f}")
    if est["expected_usd"] > cap + 1e-9:
        raise PlanRefused(f"the estimate ${est['expected_usd']:.2f} exceeds the cap ${cap:.2f}")
    tb = plan["terminal_bench"]
    for key in ("per_task_cap_usd", "min_task_reserve_usd", "neutral_call_reserve_usd", "shard_deadline_minutes",
                "per_task_wall_minutes", "agent_timeout_multiplier"):
        if key in tb and not (float(tb[key]) > 0):
            raise PlanRefused(f"terminal_bench.{key} must be positive")
    if "shard_deadline_minutes" in tb and float(tb["shard_deadline_minutes"]) > 340:
        raise PlanRefused("a shard deadline above 340 minutes outlives the 350-minute job")
    tasks = tb["tasks"]
    if len(tasks) != len(set(tasks)) or not tasks:
        raise PlanRefused("the frozen task list must be non-empty and unique")
    if subset_digest(tasks) != plan["terminal_bench"]["subset_digest"]:
        raise PlanRefused("the frozen task subset does not match its recorded digest")


def subset_digest(tasks: list[str]) -> str:
    return "sha256:" + hashlib.sha256("\n".join(tasks).encode("utf-8")).hexdigest()


def _model_ids(plan: dict[str, Any]) -> list[str]:
    ids = [plan["model"]["id"], plan["tau2"]["user_llm"]]
    return [str(i) for i in ids]


def check_dispatch(root: Path, *, ftq_id: str, plan_digest: str, cap_usd: str | float) -> dict[str, Any]:
    root = Path(root)
    if not FTQ_ID.match(ftq_id or ""):
        raise PlanRefused("the FTQ id is malformed")
    approval_path = root / "evals" / "approvals" / f"{ftq_id}.json"
    if not approval_path.is_file():
        raise PlanRefused(f"no approval file at evals/approvals/{ftq_id}.json on this tree")
    approval = load(approval_path)
    if approval.get("ftq_id") != ftq_id or approval.get("single_use") is not True:
        raise PlanRefused("the approval file does not name this FTQ id as single use")
    try:
        cap = float(cap_usd)
    except (TypeError, ValueError) as error:
        raise PlanRefused("the cap input is not a number") from error
    if abs(cap - float(approval["cap_usd"])) > 1e-9:
        raise PlanRefused("the cap input differs from the approved cap")
    if plan_digest != approval.get("plan_digest"):
        raise PlanRefused("the plan digest input differs from the approved digest")
    plan_rel = str(approval.get("plan", ""))
    if not re.fullmatch(r"evals/plans/[a-z0-9-]+\.json", plan_rel):
        raise PlanRefused("the approval names a plan outside evals/plans/")
    plan = load(root / plan_rel)
    if digest(plan) != plan_digest:
        raise PlanRefused("the plan on this tree does not match the approved digest")
    if abs(float(plan["cap_usd"]) - cap) > 1e-9:
        raise PlanRefused("the plan's own cap differs from the approved cap")
    if (root / "evals" / "results" / ftq_id).exists() or (root / "evals" / "approvals" / "used" / f"{ftq_id}.json").exists():
        raise PlanRefused("this approval has already been used")
    validate(plan)
    return {"plan": plan, "plan_path": plan_rel, "approval": approval, "estimate": estimate(plan)}
