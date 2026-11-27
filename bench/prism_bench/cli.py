"""`python -m prism_bench.cli <command>`: what harness-bench.yml runs.

    check     refuse unless the owner's approval matches the dispatch (no provider call)
    run-tb    one Terminal-Bench shard of one arm, one Harbor invocation per task, in plan order
    run-tau2  one tau2 arm
    collect   merge every shard's trials into results.json with the metrics
    scrub     fail on key-shaped content

Benchmarks are third-party code with a provider key in reach, so run-tb and
run-tau2 pass the key on to child processes by variable name only, and only
the variables the arm needs.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path
from typing import Any

from . import artifacts, metrics, plan as plans, scrub
from .ledger import Ledger

AGENTS = {
    "prism": "prism_bench.harbor_agent:PrismHarnessAgent",
    "terminus-2": "prism_bench.harbor_agent:CappedTerminus2",
}
TAU2_AGENTS = {"prism": "prism_harness", "llm_agent": "capped_llm_agent"}


def _write_output(values: dict[str, str]) -> None:
    target = os.environ.get("GITHUB_OUTPUT")
    if target:
        with open(target, "a", encoding="utf-8") as handle:
            for key, value in values.items():
                handle.write(f"{key}={value}\n")


def cmd_check(args: argparse.Namespace) -> int:
    try:
        checked = plans.check_dispatch(Path(args.root), ftq_id=args.ftq_id, plan_digest=args.plan_digest, cap_usd=args.cap)
    except plans.PlanRefused as refused:
        print(f"::error::Refusing to start: {refused}")
        return 1
    plan = checked["plan"]
    tb = plan["terminal_bench"]
    tb_matrix = [{"arm": arm, "shard": s, "shards": int(tb["shards"])} for arm in tb["arms"] for s in range(int(tb["shards"]))]
    tau_matrix = [{"arm": arm} for arm in plan["tau2"]["arms"]]
    print(json.dumps({"plan": checked["plan_path"], "estimate": checked["estimate"]}, indent=1))
    driver_ref = str(plan["harness"]["driver_ref"])
    if len(driver_ref) != 40 or any(c not in "0123456789abcdef" for c in driver_ref):
        print("::error::Refusing to start: harness.driver_ref must be a full commit SHA")
        return 1
    _write_output({"plan_path": checked["plan_path"], "tb_matrix": json.dumps(tb_matrix), "tau_matrix": json.dumps(tau_matrix),
                   "driver_ref": driver_ref})
    return 0


def _shard_tasks(plan: dict[str, Any], shard: int, shards: int) -> list[str]:
    return [t for i, t in enumerate(plan["terminal_bench"]["tasks"]) if i % shards == shard]


def cmd_run_tb(args: argparse.Namespace) -> int:
    plan = plans.load(Path(args.plan))
    plans.validate(plan)
    tb = plan["terminal_bench"]
    arm = args.arm
    if arm not in AGENTS or arm not in tb["arms"]:
        print(f"::error::unknown Terminal-Bench arm {arm}")
        return 2
    out = Path(args.out).resolve()
    out.mkdir(parents=True, exist_ok=True)
    shard_cap = float(tb["arms"][arm]["cap_usd"]) / int(args.shards)
    ledger = Ledger(out / "ledger.json", shard_cap)
    env = {**os.environ, "PRISM_BENCH_PLAN": str(Path(args.plan).resolve()), "PRISM_BENCH_ARM_LEDGER": str(out / "ledger.json"),
           "PRISM_BENCH_ARM_CAP": repr(shard_cap)}
    if arm == "prism":
        env["PRISM_BENCH_DRIVER"] = args.driver
        env["PRISM_BENCH_HARNESS_VERSION"] = plan["harness"]["version"]
    skipped: list[str] = []
    # A hosted job dies at its timeout and takes the shard's artifacts with it,
    # so a task that cannot finish before the deadline is not started.
    deadline = time.monotonic() + 60 * float(tb["shard_deadline_minutes"])
    per_task = 60 * (float(tb["per_task_wall_minutes"]))
    for task in _shard_tasks(plan, args.shard, int(args.shards)):
        if ledger.remaining() < float(tb["min_task_reserve_usd"]) or time.monotonic() + per_task > deadline:
            skipped.append(task)
            continue
        command = [shutil.which("harbor") or "harbor", "run", "-d", tb["dataset"], "-a", AGENTS[arm],
                   "-m", f"{plan['model']['provider']}/{plan['model']['id']}", "-i", task, "-i", f"*/{task}", "-n", "1",
                   "-k", str(int(tb.get("attempts", 1))), "-o", str(out / "jobs" / task),
                   "--agent-timeout-multiplier", str(tb["agent_timeout_multiplier"])]
        if arm == "terminus-2" and plan["model"]["reasoning_effort"] != "default":
            command += ["--ak", f"reasoning_effort={plan['model']['reasoning_effort']}"]
        print(f"::group::{arm} {task} (spent ${ledger.spent():.4f} of ${shard_cap:.2f})", flush=True)
        subprocess.run(command, env={**env, "PRISM_BENCH_TASK": task}, check=False)
        print("::endgroup::", flush=True)
    rows = collect_tb(out, arm) + [_not_run("terminal_bench", arm, t) for t in skipped]
    (out / "trials.jsonl").write_text("".join(json.dumps(r) + "\n" for r in rows), encoding="utf-8")
    return 0


def _not_run(bench: str, arm: str, task: str) -> dict[str, Any]:
    return {"benchmark": bench, "arm": arm, "task_id": task, "trial": 0, "status": "not_run", "reward": None,
            "tokens": {k: 0 for k in metrics.TOKEN_KEYS}, "cost_usd": 0.0, "steps": 0, "wall_seconds": 0.0}


def _reward(result: dict[str, Any]) -> float | None:
    rewards = ((result.get("verifier_result") or {}).get("rewards")) or {}
    value = rewards.get("reward", next(iter(rewards.values()), None) if rewards else None)
    return float(value) if isinstance(value, (int, float)) else None


def collect_tb(out: Path, arm: str) -> list[dict[str, Any]]:
    """One row per Harbor trial: the agent's own record, graded by the verifier's reward."""
    rows = []
    for result_path in sorted((out / "jobs").glob("*/*/*/result.json")):
        result = json.loads(result_path.read_text(encoding="utf-8"))
        if "task_name" not in result:
            continue
        trial_dir = result_path.parent
        task = str(result["task_name"]).split("/")[-1]
        reward = _reward(result)
        solved = reward is not None and reward >= 1.0 - 1e-9
        own = trial_dir / "agent" / "prism-trial.json"
        if own.exists():
            row = json.loads(own.read_text(encoding="utf-8"))
            row["task_id"] = task
        else:
            ctx = result.get("agent_result") or {}
            n_in, n_cache = int(ctx.get("n_input_tokens") or 0), int(ctx.get("n_cache_tokens") or 0)
            marker = trial_dir / "agent" / "prism-bench-status.json"
            status = json.loads(marker.read_text(encoding="utf-8"))["status"] if marker.exists() else "graded"
            steps = 0
            trajectory = trial_dir / "agent" / "trajectory.json"
            if trajectory.exists():
                steps = sum(1 for s in json.loads(trajectory.read_text(encoding="utf-8")).get("steps", []) if s.get("source") == "agent")
            charged = json.loads(marker.read_text(encoding="utf-8")).get("charged_usd") if marker.exists() else None
            row = {"benchmark": "terminal_bench", "arm": arm, "task_id": task, "trial": 0, "status": status,
                   # Terminus-2 reports prompt tokens INCLUDING cache and cache hits, not cache writes:
                   # writes are inside fresh_input here, and labelled so.
                   "tokens": {"fresh_input": max(0, n_in - n_cache), "cache_write": 0, "cache_read": n_cache,
                              "output": int(ctx.get("n_output_tokens") or 0)},
                   "tokens_note": "cache writes not separated by Terminus-2; counted in fresh_input",
                   "cost_usd": float(charged if charged is not None else (ctx.get("cost_usd") or 0.0)), "steps": steps,
                   "wall_seconds": None, "agent": "terminus-2"}
        # The grader decides a run that ended on its own; a stopped run is
        # cap_stopped whatever the container looks like.
        if row["status"] == "graded":
            row["status"] = "solved" if solved else "failed"
        row["reward"] = reward
        row["harbor_exception"] = (result.get("exception_info") or {}).get("exception_type")
        rows.append(row)
    return rows


def cmd_run_tau2(args: argparse.Namespace) -> int:
    plan = plans.load(Path(args.plan))
    plans.validate(plan)
    tau = plan["tau2"]
    arm = args.arm
    if arm not in TAU2_AGENTS or arm not in tau["arms"]:
        print(f"::error::unknown tau2 arm {arm}")
        return 2
    out = Path(args.out).resolve()
    out.mkdir(parents=True, exist_ok=True)
    cap = float(tau["arms"][arm]["cap_usd"])
    env = {**os.environ, "PRISM_BENCH_PLAN": str(Path(args.plan).resolve()), "PRISM_BENCH_ARM_LEDGER": str(out / "ledger.json"),
           "PRISM_BENCH_ARM_CAP": repr(cap), "PRISM_BENCH_RECORDS": str(out)}
    if arm == "prism":
        env["PRISM_BENCH_DRIVER"] = args.driver
    save = f"g393-{arm}"
    command = [sys.executable, "-m", "prism_bench.tau2_run", "run", "--domain", tau["domain"], "--agent", TAU2_AGENTS[arm],
               "--agent-llm", f"{plan['model']['provider']}/{plan['model']['id']}", "--user", "capped_user_simulator",
               "--user-llm", tau["user_llm"], "--num-tasks", str(tau["num_tasks"]), "--num-trials", str(tau["num_trials"]),
               "--seed", str(tau["seed"]), "--max-concurrency", "1", "--save-to", save]
    subprocess.run(command, env=env, check=False, cwd=args.tau2_root)
    results = sorted(Path(args.tau2_root).rglob(f"{save}*/results.json")) + sorted(Path(args.tau2_root).rglob(f"{save}*.json"))
    rows = collect_tau2(results[0], arm, out) if results else []
    (out / "trials.jsonl").write_text("".join(json.dumps(r) + "\n" for r in rows), encoding="utf-8")
    if results:
        (out / "tau2-results.json").write_bytes(results[0].read_bytes())
    return 0


def collect_tau2(results_path: Path, arm: str, out: Path) -> list[dict[str, Any]]:
    data = json.loads(results_path.read_text(encoding="utf-8"))
    cap_stopped = (out / "cap-stops.jsonl").exists()
    rows = []
    for sim in data.get("simulations", []):
        reward = (sim.get("reward_info") or {}).get("reward")
        agent_msgs = [m for m in sim.get("messages", []) if m.get("role") == "assistant"]
        usage = [m.get("usage") or {} for m in agent_msgs]
        tokens = {
            "fresh_input": sum(int(u.get("fresh_input_tokens", u.get("prompt_tokens", 0))) for u in usage),
            "cache_write": sum(int(u.get("cache_write_tokens", 0)) for u in usage),
            "cache_read": sum(int(u.get("cache_read_tokens", 0)) for u in usage),
            "output": sum(int(u.get("completion_tokens", 0)) for u in usage),
        }
        termination = str(sim.get("termination_reason", ""))
        if isinstance(reward, (int, float)) and abs(float(reward) - 1.0) < 1e-6:
            status = "solved"
        elif "error" in termination and cap_stopped:
            status = "cap_stopped"
        else:
            status = "failed"
        rows.append({"benchmark": "tau2", "arm": arm, "task_id": str(sim.get("task_id")), "trial": int(sim.get("trial") or 0),
                     "seed": sim.get("seed"), "status": status, "reward": reward, "tokens": tokens,
                     "cost_usd": float(sim.get("agent_cost") or 0.0), "user_cost_usd": float(sim.get("user_cost") or 0.0),
                     "steps": len(agent_msgs), "wall_seconds": sim.get("duration"), "termination_reason": termination})
    return rows


def cmd_collect(args: argparse.Namespace) -> int:
    plan = plans.load(Path(args.plan))
    plans.validate(plan)
    # The artifacts come from jobs that ran third-party benchmark code: only data
    # that passes artifacts.validate (exact files, allow-listed fields, plan-bound
    # arms/tasks, numeric bounds) reaches the signed results, with its digests.
    try:
        checked = artifacts.validate(Path(args.artifacts), plan)
    except artifacts.ArtifactRefused as refused:
        print(f"::error::Refusing to publish: {refused}")
        return 1
    rows, spent = checked["rows"], checked["ledgers"]
    results = {
        "schema": "prism-labs/harness-bench-results/1", "ftq_id": args.ftq_id, "plan_path": args.plan_rel,
        "plan_digest": plans.digest(plan), "cap_usd": plan["cap_usd"], "model": plan["model"], "harness": plan["harness"],
        "estimate": plans.estimate(plan), "ledgers": spent,
        "spent_usd": round(sum(float(v["spent_usd"]) for v in spent.values()), 6),
        "run": {"repository": os.environ.get("GITHUB_REPOSITORY"), "run_id": os.environ.get("GITHUB_RUN_ID"),
                "sha": os.environ.get("GITHUB_SHA")},
        "inputs": checked["inputs"], "missing_artifacts": checked["missing"],
        "metrics": metrics.compare(rows), "trials": rows,
        "labels": {"prices": "Lab ModelPrices, dated in the plan", "board_rows": "reference only; full set, not comparable to this subset"},
    }
    Path(args.out).write_text(json.dumps(results, indent=1, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"collected {len(rows)} trials, ${results['spent_usd']:.4f} spent of ${plan['cap_usd']}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="prism_bench")
    sub = parser.add_subparsers(dest="command", required=True)
    p = sub.add_parser("check")
    p.add_argument("--root", default=".")
    p.add_argument("--ftq-id", required=True)
    p.add_argument("--plan-digest", required=True)
    p.add_argument("--cap", required=True)
    p.set_defaults(func=cmd_check)
    p = sub.add_parser("run-tb")
    p.add_argument("--plan", required=True)
    p.add_argument("--arm", required=True)
    p.add_argument("--shard", type=int, required=True)
    p.add_argument("--shards", type=int, required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--driver", default="[]")
    p.set_defaults(func=cmd_run_tb)
    p = sub.add_parser("run-tau2")
    p.add_argument("--plan", required=True)
    p.add_argument("--arm", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--tau2-root", required=True)
    p.add_argument("--driver", default="[]")
    p.set_defaults(func=cmd_run_tau2)
    p = sub.add_parser("collect")
    p.add_argument("--plan", required=True)
    p.add_argument("--plan-rel", required=True)
    p.add_argument("--ftq-id", required=True)
    p.add_argument("--artifacts", required=True)
    p.add_argument("--out", required=True)
    p.set_defaults(func=cmd_collect)
    p = sub.add_parser("scrub")
    p.add_argument("paths", nargs="+")
    p.set_defaults(func=lambda a: scrub.main(a.paths))
    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    sys.exit(main())
