"""One driver trial: the protocol loop, the arm cap, and the record it leaves.

`TrialRecorder` turns the driver's events into (a) the trial record the metrics
read and (b) an ATIF trajectory, in the shape Harbor's submission rules ask for
(harbor.models.trajectories, schema ATIF-v1.8). The ATIF is plain dicts here so
this module runs without Harbor installed; Harbor validates it on upload.

Step attribution follows the protocol's measured ordering: the tool calls
between two `usage` lines belong to the LATER model call.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Awaitable, Callable

from .driver import DriverError, DriverSession
from .ledger import CapReached, Ledger

TOOL_OUTPUT_LIMIT = 64 * 1024


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def clip(text: str, limit: int = TOOL_OUTPUT_LIMIT) -> str:
    """Bound what goes back to the model. The cut is stated, never silent."""
    data = text.encode("utf-8", "replace")
    if len(data) <= limit:
        return text
    head = data[: limit // 2].decode("utf-8", "ignore")
    tail = data[-limit // 2:].decode("utf-8", "ignore")
    return f"{head}\n[... {len(data) - limit} bytes omitted by the benchmark adapter ...]\n{tail}"


@dataclass
class TrialRecorder:
    benchmark: str
    arm: str
    task_id: str
    trial: int
    model: str
    reasoning_effort: str
    agent_name: str
    agent_version: str
    tool_definitions: list[dict[str, Any]] = field(default_factory=list)
    steps: list[dict[str, Any]] = field(default_factory=list)
    tokens: dict[str, int] = field(default_factory=lambda: {"fresh_input": 0, "cache_write": 0, "cache_read": 0, "output": 0})
    cost_usd: float = 0.0
    model_calls: int = 0
    approvals: int = 0
    end: dict[str, Any] | None = None
    status: str = "error"
    started: float = field(default_factory=time.monotonic)
    _pending_calls: list[dict[str, Any]] = field(default_factory=list)
    _pending_results: list[dict[str, Any]] = field(default_factory=list)

    def user(self, text: str) -> None:
        self.steps.append({"step_id": len(self.steps) + 1, "timestamp": _now(), "source": "user", "message": text})

    def tool_call(self, call: dict[str, Any]) -> None:
        self._pending_calls.append({"tool_call_id": call["call_id"], "function_name": call["name"], "arguments": call.get("arguments") or {}})

    def tool_result(self, call_id: str, output: str) -> None:
        self._pending_results.append({"source_call_id": call_id, "content": output})

    def usage(self, event: dict[str, Any]) -> None:
        fresh, write, read, out = (int(event[k]) for k in ("input_tokens", "cache_write_tokens", "cache_read_tokens", "output_tokens"))
        for key, value in zip(("fresh_input", "cache_write", "cache_read", "output"), (fresh, write, read, out)):
            self.tokens[key] += value
        self.cost_usd += float(event["charged_usd"])
        self.model_calls += 1
        step: dict[str, Any] = {
            "step_id": len(self.steps) + 1, "timestamp": _now(), "source": "agent", "message": "",
            "model_name": self.model, "reasoning_effort": self.reasoning_effort,
            "metrics": {"prompt_tokens": fresh + write + read, "completion_tokens": out, "cached_tokens": read,
                        "cost_usd": float(event["charged_usd"]),
                        "extra": {"cache_write_tokens": write, "reasoning_tokens": event.get("reasoning_tokens"),
                                  "usage_known": event.get("usage_known"), "outcome": event.get("outcome")}},
        }
        if self._pending_calls:
            step["tool_calls"] = self._pending_calls
            step["observation"] = {"results": self._pending_results}
        self._pending_calls, self._pending_results = [], []
        self.steps.append(step)

    def reply(self, text: str) -> None:
        for step in reversed(self.steps):
            if step["source"] == "agent":
                step["message"] = text
                return

    def record(self, *, reward: float | None, solved: bool | None) -> dict[str, Any]:
        """The metrics row. A stopped run is never solved, whatever the grader says."""
        status = self.status
        if status == "graded" and solved is not None:
            status = "solved" if solved else "failed"
        return {
            "benchmark": self.benchmark, "arm": self.arm, "task_id": self.task_id, "trial": self.trial,
            "status": status, "reward": reward, "tokens": dict(self.tokens), "cost_usd": round(self.cost_usd, 8),
            "steps": self.model_calls, "wall_seconds": round(time.monotonic() - self.started, 1),
            "driver_end": (self.end or {}).get("status"), "driver_code": (self.end or {}).get("code"),
            "approvals_auto_granted": self.approvals, "model": self.model, "reasoning_effort": self.reasoning_effort,
            "agent": self.agent_name, "agent_version": self.agent_version,
        }

    def atif(self, session_id: str) -> dict[str, Any]:
        steps = self.steps or [{"step_id": 1, "timestamp": _now(), "source": "system", "message": "no steps were taken"}]
        return {
            "schema_version": "ATIF-v1.8", "session_id": session_id,
            "agent": {"name": self.agent_name, "version": self.agent_version, "model_name": self.model,
                      "tool_definitions": self.tool_definitions},
            "steps": steps,
            "final_metrics": {
                "total_prompt_tokens": self.tokens["fresh_input"] + self.tokens["cache_write"] + self.tokens["cache_read"],
                "total_completion_tokens": self.tokens["output"], "total_cached_tokens": self.tokens["cache_read"],
                "total_cost_usd": round(self.cost_usd, 8), "total_steps": len(steps),
                "extra": {"cache_write_tokens": self.tokens["cache_write"], "driver_end": (self.end or {}).get("status")},
            },
        }


STOPPED = {"budget_stopped": "cap_stopped", "aborted": "cap_stopped"}


async def run_driver_trial(session: DriverSession, start: dict[str, Any], recorder: TrialRecorder, ledger: Ledger,
                           execute: Callable[[dict[str, Any]], Awaitable[tuple[str, bool]]],
                           next_turn: Callable[[str], Awaitable[str | None]] | None = None) -> TrialRecorder:
    """Drive one trial to its `end`. `execute` runs a tool call in the benchmark's sandbox.

    The arm ledger is charged from each `usage` line as it arrives. Before each
    tool result is returned (which lets the model make another call), the arm
    is checked: once it is spent, the run is aborted and recorded cap_stopped.
    The driver's own cap was already set to what the arm has left, so this is
    the second of two independent stops, not the only one.
    """
    recorder.user(start["instruction"])
    session.send("start", **start)
    capped = False
    try:
        while True:
            event = session.next()
            kind = event["type"]
            if kind == "usage":
                recorder.usage(event)
                ledger.charge(float(event["charged_usd"]))
            elif kind == "approval":
                recorder.approvals += 1
            elif kind == "tool_call":
                recorder.tool_call(event)
                if ledger.remaining() <= 0:
                    capped = True
                    session.send("abort", reason="the arm's cost cap is spent")
                    continue
                output, is_error = await execute(event)
                output = clip(output)
                recorder.tool_result(event["call_id"], output)
                session.send("tool_result", call_id=event["call_id"], output=output, is_error=is_error)
            elif kind == "reply":
                recorder.reply(str(event.get("text", "")))
                follow = await next_turn(str(event.get("text", ""))) if next_turn else None
                if follow is None:
                    session.send("close")
                else:
                    recorder.user(follow)
                    session.send("user", text=follow)
            elif kind == "end":
                recorder.end = event
                status = event.get("status")
                if capped or status in STOPPED:
                    recorder.status = "cap_stopped"
                elif status in ("refused", "protocol_error"):
                    # The run never really started: no grade describes it.
                    recorder.status = "error"
                else:
                    recorder.status = "graded"
                return recorder
            else:
                raise DriverError(f"unexpected driver message {kind!r}")
    except CapReached:
        recorder.status = "cap_stopped"
        return recorder
