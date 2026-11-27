"""Lab consumer scenario for prism-ai-harness (Python port).

Runs inside a throwaway virtual environment that installed the built wheel FILE, so the package is imported from
that environment's site-packages exactly as a consumer imports it. Public exports only; a fake model client; the
network is blocked for the whole process and the scenario fails if anything reached for it.

For one gated call, it checks four things across every case below:
  1. the call runs at most once;
  2. every gated call in the thread (the parked one, and each call a recorded copy holds) has exactly one outcome;
  3. the call never runs with arguments other than those in the request the person approved;
  4. one approval never runs two calls.

Run with ``python -I`` from a directory that holds nothing importable.
"""

from __future__ import annotations

import copy
import json
import os
import socket
import sys
import sysconfig
import tempfile
from pathlib import Path
from typing import Any, Callable

# --- Network block, installed before the package is loaded ---------------------------------------------------
NETWORK = {"connect": 0, "audit": 0}


def _blocked(*_args: Any, **_kwargs: Any) -> Any:
    NETWORK["connect"] += 1
    raise OSError("The network is blocked in this scenario.")


socket.socket.connect = _blocked  # type: ignore[method-assign]
socket.socket.connect_ex = _blocked  # type: ignore[method-assign]
socket.create_connection = _blocked  # type: ignore[assignment]


def _audit(event: str, _args: tuple[Any, ...]) -> None:
    # Sees every connect, including one made below the Python-level socket methods. It cannot be removed.
    if event in ("socket.connect", "socket.getaddrinfo"):
        NETWORK["audit"] += 1
        raise OSError("The network is blocked in this scenario.")


sys.addaudithook(_audit)

# Prove the block is live before trusting a zero later.
try:
    socket.socket(socket.AF_INET, socket.SOCK_STREAM).connect(("127.0.0.1", 9))
    raise SystemExit("A socket connect was not blocked.")
except OSError:
    pass
if NETWORK["connect"] != 1:
    raise SystemExit("socket.connect was not blocked.")
BASELINE = dict(NETWORK)

# --- The package, from an installed wheel file in this environment ------------------------------------------
import importlib.metadata as metadata  # noqa: E402

import prism_harness  # noqa: E402
from prism_harness import (  # noqa: E402
    AgentRuntime,
    FileSessionStore,
    LlmResponse,
    LlmToolCall,
    MemorySessionStore,
    ModeRegistry,
    Participant,
    PrismHarness,
    ToolRegistry,
    record_approval,
)

DIST = "prism-ai-harness"
site = Path(sysconfig.get_paths()["purelib"]).resolve()
module_file = Path(prism_harness.__file__).resolve()
if site not in module_file.parents:
    raise SystemExit(f"prism_harness was imported from outside this environment's site-packages: {module_file}")
if not sys.prefix or sys.prefix == sys.base_prefix:
    raise SystemExit("The scenario must run in a throwaway virtual environment.")
dist = metadata.distribution(DIST)
direct = json.loads(dist.read_text("direct_url.json") or "{}")
url = str(direct.get("url", ""))
if "archive_info" not in direct or not url.startswith("file:") or not url.endswith(".whl"):
    raise SystemExit(f"{DIST} was not installed from a wheel file (direct_url: {json.dumps(direct)}).")
if direct.get("dir_info", {}).get("editable"):
    raise SystemExit(f"{DIST} is an editable install.")
print(f"Installed {DIST} {dist.version} from {url}")
if os.environ.get("HARNESS_SHA"):
    print(f"Candidate commit: {os.environ['HARNESS_SHA']}")

# --- Rig ------------------------------------------------------------------------------------------------------
CALL_ID = "c1"
CALL_ARGS = {"n": 1}
_counter = {"sessions": 0}
_tmp = tempfile.mkdtemp(prefix="lab-harness-consumer-")


class Echo:
    def __init__(self, ran: list[Any]) -> None:
        self._ran = ran

    @property
    def name(self) -> str:
        return "echo"

    def handle(self, args: dict[str, Any]) -> Any:
        self._ran.append(json.loads(json.dumps(args)))
        return f"ran:{json.dumps(args, sort_keys=True)}"


class Free:
    @property
    def name(self) -> str:
        return "free"

    def handle(self, args: dict[str, Any]) -> Any:
        return "free"


def rig(max_steps: int = 1, fail_after_resolution: bool = False) -> dict[str, Any]:
    _counter["sessions"] += 1
    directory = os.path.join(_tmp, f"s{_counter['sessions']}")
    harness = PrismHarness(
        drivers={"memory": MemorySessionStore, "files": lambda: FileSessionStore(directory)},
        stores={"ephemeral": "memory", "durable": "files"},
    )
    r: dict[str, Any] = {"ran": [], "requests": 0}
    r["session"] = harness.for_(Participant("User", 1)).session(f"lab-{_counter['sessions']}")
    modes = ModeRegistry(
        {
            "default": "guarded",
            "modes": {
                "guarded": {
                    "system_prompt": "Careful.",
                    "tools": ["echo", "free"],
                    "max_steps": max_steps,
                    "requires_approval": ["echo"],
                }
            },
        }
    )

    def client(_request: Any) -> LlmResponse:
        r["requests"] += 1
        if r["requests"] == 1:
            return LlmResponse(
                text="", finish_reason="tool_calls", tool_calls=[LlmToolCall(CALL_ID, "echo", dict(CALL_ARGS))]
            )
        if fail_after_resolution:
            raise RuntimeError("fake model failure")
        return LlmResponse(text="done", finish_reason="stop")

    r["agent"] = AgentRuntime(
        client=client, modes=modes, tools=ToolRegistry().register(Echo(r["ran"])).register(Free())
    )
    return r


def rows(session: Any) -> list[dict[str, Any]]:
    return [entry.message for entry in session.thread().messages()]


def canonical(value: Any) -> str:
    parsed = value
    if isinstance(value, str):
        try:
            parsed = json.loads(value)
        except ValueError:
            return json.dumps(value)
    if not isinstance(parsed, dict):
        return json.dumps(parsed)
    return json.dumps(parsed, sort_keys=True, separators=(",", ":"))


def calls_and_outcomes(session: Any, call_id: str) -> tuple[list[str], list[str]]:
    """Every call with this id the thread holds, and every outcome recorded for one, as canonical arguments.

    A recorded copy of a step is a step of its own, so its calls are calls of their own: each call instance must
    have exactly one outcome, checked as equality of the two multisets.
    """
    calls: list[str] = []
    outcomes: list[str] = []
    for row in rows(session):
        if row.get("type") == "assistant" and isinstance(row.get("tool_calls"), list):
            for call in row["tool_calls"]:
                if isinstance(call, dict) and call.get("id") == call_id:
                    calls.append(canonical(call.get("arguments")))
        if row.get("type") == "tool_result" and isinstance(row.get("tool_results"), list):
            for entry in row["tool_results"]:
                if isinstance(entry, dict) and entry.get("tool_call_id") == call_id:
                    outcomes.append(canonical(entry.get("args")))
    return sorted(calls), sorted(outcomes)


def resume_thrice(r: dict[str, Any]) -> list[str]:
    errors = []
    for _ in range(3):
        try:
            r["agent"].send(r["session"], "")
        except Exception as error:  # noqa: BLE001 -- recorded, then judged by the invariants
            errors.append(f"{type(error).__name__}: {str(error)[:100]}")
    return errors


def changed(call: Any) -> Any:
    out = copy.deepcopy(call)
    out["arguments"] = '{"n":2}' if isinstance(call.get("arguments"), str) else {"n": 2}
    return out


def original(call: Any) -> Any:
    return copy.deepcopy(call)


CALL_JSON = json.dumps({"id": CALL_ID, "name": "echo", "arguments": CALL_ARGS})
SHAPES: dict[str, Callable[[Any], list[Any]]] = {
    "string": lambda c: ["x"],
    "json-string": lambda c: [CALL_JSON],
    "number": lambda c: [1],
    "null": lambda c: [None],
    "array-of-strings": lambda c: [["x", "y"]],
    "mixed": lambda c: ["x", original(c), None, 1],
    "empty": lambda c: [],
    "changed-args": lambda c: [changed(c)],
    "string-then-changed": lambda c: ["x", changed(c)],
    "null-then-changed": lambda c: [None, changed(c)],
    "changed-then-mixed": lambda c: [changed(c), "x", None],
    "original-and-changed": lambda c: [original(c), changed(c)],
    "exact": lambda c: [original(c)],
}


def run_case(
    decision: bool,
    shape: str | None = None,
    keep_request: bool = True,
    order: str = "A",
    max_steps: int = 1,
    fail_after_resolution: bool = False,
) -> dict[str, Any]:
    """`order` A decides, then records the copy; B records the copy, then decides."""
    r = rig(max_steps, fail_after_resolution)
    session = r["session"]
    first = r["agent"].send(session, "go")
    pending = list(first.pending_approvals)
    if first.finish_reason != "awaiting_approval" or len(pending) != 1:
        return {"problems": [f"did not park (finish_reason {first.finish_reason}, {len(pending)} pending)"]}
    request = pending[0]
    approved_arguments = copy.deepcopy(request.arguments)
    state = {"decided": False}

    def decide() -> None:
        try:
            record_approval(session, request.id, decision)
            state["decided"] = True
        except Exception:  # noqa: BLE001 -- a refused decision is a legitimate outcome here
            state["decided"] = False

    if order == "A":
        decide()
    if shape is not None:
        parked = next(
            row
            for row in reversed(rows(session))
            if row.get("type") == "assistant" and isinstance(row.get("tool_calls"), list) and row["tool_calls"]
        )
        step = copy.deepcopy(parked)
        step["tool_calls"] = SHAPES[shape](parked["tool_calls"][0])
        if not keep_request:
            step["tool_approval_requests"] = []
        session.thread().record([step])
    if order == "B":
        decide()
    errors = resume_thrice(r)
    calls, outcomes = calls_and_outcomes(session, CALL_ID)

    problems = []
    ran = r["ran"]
    if len(ran) > 1:
        problems.append(f"the call ran {len(ran)} times")
    if canonical(approved_arguments) not in calls:
        problems.append("the gated call is missing from the thread")
    if calls != outcomes:
        problems.append(f"calls {calls} do not each have exactly one outcome {outcomes}")
    if not (decision and state["decided"]) and ran:
        problems.append("the call ran without an approval")
    # Without a copy the person's decision must land and be honoured: an approved call runs exactly once, a denied
    # one never. Otherwise a harness that refused every decision, or ran nothing, would pass.
    if shape is None and not state["decided"]:
        problems.append("the decision was refused")
    if shape is None and decision and len(ran) != 1:
        problems.append(f"the approved call ran {len(ran)} times, expected once")
    for args in ran:
        if canonical(args) != canonical(approved_arguments):
            problems.append(f"the call ran with arguments the person did not approve: {json.dumps(args)}")
    return {"problems": problems, "ran": len(ran), "outcomes": len(outcomes), "decided": state["decided"], "errors": errors}


cases: list[tuple[str, dict[str, Any]]] = []
for decision in (True, False):
    verb = "approve" if decision else "deny"
    cases.append((f"{verb}, resume x3", {"decision": decision}))
    cases.append(
        (
            f"{verb}, resume x3, max_steps 6, model fails after resolution",
            {"decision": decision, "max_steps": 6, "fail_after_resolution": True},
        )
    )
for shape in SHAPES:
    for keep_request in (True, False):
        for order in ("A", "B"):
            for max_steps, fail_after in ((1, False), (6, True)):
                budget = "max_steps 1" if max_steps == 1 else "max_steps 6, model fails after resolution"
                cases.append(
                    (
                        f"copy-of-step {shape}, {'with' if keep_request else 'without'} request, order {order}, {budget}",
                        {
                            "decision": True,
                            "shape": shape,
                            "keep_request": keep_request,
                            "order": order,
                            "max_steps": max_steps,
                            "fail_after_resolution": fail_after,
                        },
                    )
                )

failed = 0
for label, kwargs in cases:
    try:
        result = run_case(**kwargs)
    except Exception as error:  # noqa: BLE001 -- a crash is a failed case, not a crashed lane
        result = {"problems": [f"threw {type(error).__name__}: {str(error)[:160]}"]}
    ok = not result["problems"]
    failed += 0 if ok else 1
    detail = "" if ok else " -- " + "; ".join(result["problems"])
    print(
        f"{'ok  ' if ok else 'FAIL'} {label}{detail} "
        f"(ran {result.get('ran', '-')}, outcomes {result.get('outcomes', '-')}, decided {result.get('decided', '-')})"
    )

used = {key: NETWORK[key] - BASELINE[key] for key in NETWORK}
network_ok = all(value == 0 for value in used.values())
if not network_ok:
    print(f"FAIL the network was reached for: {json.dumps(used)}")
print(f"{len(cases) - failed} of {len(cases)} cases passed; network use {json.dumps(used)}.")
if failed or not network_ok:
    print(f"::error::{failed + (0 if network_ok else 1)} harness consumer check(s) failed.")
    sys.exit(1)
