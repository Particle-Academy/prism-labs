"""Validate the run jobs' metrics artifacts before the write job trusts any of it.

The run jobs execute third-party benchmark code (Harbor, tau2, the task
containers' output) with a provider key in reach, so whatever they upload is
UNTRUSTED input to `publish`, the one job that can write to this repository.
Nothing is read into results.json until it has passed this module:

- the artifact directory set is exactly what the plan's matrix produces
  (`bench-tb-<arm>-<shard>-metrics`, `bench-tau2-<arm>-metrics`), and each holds
  exactly `trials.jsonl` and `ledger.json`: regular files, no links, size-capped;
- every row is re-built from an allow-list of fields with checked types and
  bounds; its benchmark, arm (and for Terminal-Bench, task) must be the ones the
  plan and the directory name say; duplicates are refused;
- each ledger's cap equals the cap that shard was given, and its spend is a
  finite non-negative number;
- the sha256 of every consumed file is recorded, so the signed results.json
  states exactly which bytes it was computed from.

Any violation refuses the whole set: a partially trusted result is not signed.
"""

from __future__ import annotations

import hashlib
import json
import math
import re
from pathlib import Path
from typing import Any

MAX_FILE_BYTES = 4 * 1024 * 1024
MAX_ROWS = 2000
STATUSES = {"solved", "failed", "cap_stopped", "error", "not_run"}
TASK_ID = re.compile(r"^[A-Za-z0-9_.:-]{1,128}$")
TOKEN_KEYS = ("fresh_input", "cache_write", "cache_read", "output")


class ArtifactRefused(RuntimeError):
    pass


def _number(value: Any, what: str, upper: float) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0 or value > upper:
        raise ArtifactRefused(f"{what} is not a finite number in [0, {upper}]")
    return float(value)


def _integer(value: Any, what: str, upper: int) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0 or value > upper:
        raise ArtifactRefused(f"{what} is not an integer in [0, {upper}]")
    return value


def expected_dirs(plan: dict[str, Any]) -> dict[str, dict[str, Any]]:
    tb, tau = plan["terminal_bench"], plan["tau2"]
    out = {}
    for arm, spec in tb["arms"].items():
        for shard in range(int(tb["shards"])):
            out[f"bench-tb-{arm}-{shard}-metrics"] = {"benchmark": "terminal_bench", "arm": arm,
                                                      "cap": float(spec["cap_usd"]) / int(tb["shards"]),
                                                      "tasks": {t for i, t in enumerate(tb["tasks"]) if i % int(tb["shards"]) == shard}}
    for arm, spec in tau["arms"].items():
        out[f"bench-tau2-{arm}-metrics"] = {"benchmark": "tau2", "arm": arm, "cap": float(spec["cap_usd"]), "tasks": None}
    return out


def _read(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise ArtifactRefused(f"{path.name} is not a regular file")
    if path.stat().st_size > MAX_FILE_BYTES:
        raise ArtifactRefused(f"{path.name} is larger than {MAX_FILE_BYTES} bytes")
    return path.read_bytes()


def _row(raw: Any, where: dict[str, Any], plan: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(raw, dict):
        raise ArtifactRefused("a trial row is not an object")
    if raw.get("benchmark") != where["benchmark"] or raw.get("arm") != where["arm"]:
        raise ArtifactRefused("a trial row names a benchmark or arm its artifact did not run")
    task = raw.get("task_id")
    if not isinstance(task, str) or not TASK_ID.match(task):
        raise ArtifactRefused("a trial row has a malformed task_id")
    if where["tasks"] is not None and task not in where["tasks"]:
        raise ArtifactRefused(f"task {task} is not in this shard of the frozen plan")
    if raw.get("status") not in STATUSES:
        raise ArtifactRefused("a trial row has an unknown status")
    tokens = raw.get("tokens")
    if not isinstance(tokens, dict):
        raise ArtifactRefused("a trial row has no token counts")
    trials = int(plan["tau2"]["num_trials"]) if where["benchmark"] == "tau2" else int(plan["terminal_bench"].get("attempts", 1))
    reward = raw.get("reward")
    row = {
        "benchmark": where["benchmark"], "arm": where["arm"], "task_id": task,
        "trial": _integer(raw.get("trial", 0), "trial", trials - 1),
        "status": raw["status"],
        "reward": None if reward is None else _number(reward, "reward", 1.0),
        "tokens": {k: _integer(tokens.get(k, 0), f"tokens.{k}", 10**12) for k in TOKEN_KEYS},
        "cost_usd": _number(raw.get("cost_usd", 0.0), "cost_usd", where["cap"] * 2 + 1),
        "steps": _integer(raw.get("steps", 0), "steps", 100_000),
        "wall_seconds": None if raw.get("wall_seconds") is None else _number(raw["wall_seconds"], "wall_seconds", 86_400.0),
    }
    if where["benchmark"] == "tau2" and raw.get("user_cost_usd") is not None:
        row["user_cost_usd"] = _number(raw["user_cost_usd"], "user_cost_usd", where["cap"] * 2 + 1)
    for key in ("driver_end", "agent_version", "termination_reason"):
        value = raw.get(key)
        if isinstance(value, str) and len(value) <= 200 and re.fullmatch(r"[\w .:@/+-]*", value):
            row[key] = value
    return row


def validate(root: Path, plan: dict[str, Any]) -> dict[str, Any]:
    """Return {rows, ledgers, inputs} built only from checked data, or raise ArtifactRefused."""
    root = Path(root)
    expected = expected_dirs(plan)
    present = {p.name: p for p in root.iterdir()} if root.is_dir() else {}
    for name, path in present.items():
        if name not in expected or path.is_symlink() or not path.is_dir():
            raise ArtifactRefused(f"unexpected artifact {name}")
    rows: list[dict[str, Any]] = []
    ledgers: dict[str, Any] = {}
    inputs: dict[str, str] = {}
    seen = set()
    for name, where in sorted(expected.items()):
        directory = present.get(name)
        if directory is None:
            continue  # a job that never uploaded: absent, and reported as absent
        files = {p.name for p in directory.iterdir()}
        if not files <= {"trials.jsonl", "ledger.json"}:
            raise ArtifactRefused(f"{name} holds files other than trials.jsonl and ledger.json")
        if "trials.jsonl" in files:
            data = _read(directory / "trials.jsonl")
            inputs[f"{name}/trials.jsonl"] = "sha256:" + hashlib.sha256(data).hexdigest()
            lines = [line for line in data.decode("utf-8").splitlines() if line.strip()]
            if len(lines) > MAX_ROWS:
                raise ArtifactRefused(f"{name} has more than {MAX_ROWS} rows")
            for line in lines:
                try:
                    raw = json.loads(line)
                except ValueError as error:
                    raise ArtifactRefused(f"{name} has a line that is not JSON") from error
                row = _row(raw, where, plan)
                key = (row["benchmark"], row["arm"], row["task_id"], row["trial"])
                if key in seen:
                    raise ArtifactRefused(f"duplicate trial {key}")
                seen.add(key)
                rows.append(row)
        if "ledger.json" in files:
            data = _read(directory / "ledger.json")
            inputs[f"{name}/ledger.json"] = "sha256:" + hashlib.sha256(data).hexdigest()
            try:
                ledger = json.loads(data)
            except ValueError as error:
                raise ArtifactRefused(f"{name} ledger is not JSON") from error
            if not isinstance(ledger, dict) or abs(_number(ledger.get("cap_usd"), "ledger cap", 1e6) - where["cap"]) > 1e-9:
                raise ArtifactRefused(f"{name} ledger cap is not the cap that shard was given")
            ledgers[name] = {"cap_usd": where["cap"], "spent_usd": _number(ledger.get("spent_usd"), "ledger spend", where["cap"] * 2 + 1),
                             "calls": _integer(ledger.get("calls", 0), "ledger calls", 10**7),
                             "overrun_usd": _number(ledger.get("overrun_usd", 0.0), "ledger overrun", where["cap"] + 1)}
    return {"rows": rows, "ledgers": ledgers, "inputs": inputs,
            "missing": sorted(set(expected) - set(present))}
