"""tau2-bench agents and user simulator for the tau2 arms (tau2-bench v1.0.1).

- `prism_harness`: the prism-harness arm. A HalfDuplexAgent backed by the PHP
  driver. tau2 executes each tool call; the driver never does. One driver run
  per simulation, with the simulated user's turns sent as `user` lines on the
  same session, so the cost cap and step budget are per simulation.
- `capped_llm_agent`: tau2's built-in LLMAgent, unchanged, behind the arm cap.
- `capped_user_simulator`: tau2's UserSimulator, unchanged, behind the same cap,
  because the simulator's spend is part of what an arm costs to run.

Both arms use the built-in agent's own system prompt, so the comparison is the
harness and not the prompt.

Registration has no command-line hook in tau2, so `prism_bench.tau2_run`
registers these and then calls `tau2.cli.main()`.

Configuration comes from the environment the runner sets:
PRISM_BENCH_PLAN, PRISM_BENCH_ARM_LEDGER, PRISM_BENCH_ARM_CAP, PRISM_BENCH_DRIVER
(prism arm), PRISM_BENCH_RECORDS (a directory for per-simulation cap markers).
"""

from __future__ import annotations

import json
import os
import threading
from pathlib import Path
from typing import Any, Optional

from tau2.agent.base_agent import AgentError, HalfDuplexAgent
from tau2.agent.llm_agent import AGENT_INSTRUCTION, SYSTEM_PROMPT, LLMAgent
from tau2.data_model.message import AssistantMessage, MultiToolMessage, ToolCall, ToolMessage, UserMessage
from tau2.user.user_simulator import UserSimulator

from . import plan as plans
from .driver import DriverError, DriverSession, function_schema, start_message
from .ledger import CapReached, Ledger
from .prices import conservative_cost_usd

_counter_lock = threading.Lock()
_counter = {"n": 0}


def _config() -> tuple[dict[str, Any], Ledger]:
    plan = plans.load(Path(os.environ["PRISM_BENCH_PLAN"]))
    return plan, Ledger(Path(os.environ["PRISM_BENCH_ARM_LEDGER"]), float(os.environ["PRISM_BENCH_ARM_CAP"]))


def _mark_cap_stop(who: str, detail: str) -> None:
    records = Path(os.environ.get("PRISM_BENCH_RECORDS", "."))
    records.mkdir(parents=True, exist_ok=True)
    with (records / "cap-stops.jsonl").open("a", encoding="utf-8") as handle:
        handle.write(json.dumps({"who": who, "detail": detail}) + "\n")


def _price(plan: dict[str, Any], llm: str) -> dict[str, Any]:
    provider, _, model = llm.partition("/")
    return plan["prices"][provider][model]


def _charge_message(ledger: Ledger, entry: dict[str, Any], message: Any, reserve: float) -> None:
    usage = getattr(message, "usage", None) or {}
    if not usage:
        ledger.charge(reserve)  # unknown usage is charged at the reserve, never at zero
        return
    ledger.charge(conservative_cost_usd(entry, prompt_including_cache=int(usage.get("prompt_tokens", 0)), cache_hits=0,
                                        output=int(usage.get("completion_tokens", 0)), reported_usd=getattr(message, "cost", None)))


class CappedLLMAgent(LLMAgent):
    def generate_next_message(self, message, state):
        plan, ledger = _config()
        reserve = float(plan["tau2"]["call_reserve_usd"])
        try:
            ledger.admit(reserve)
        except CapReached as refused:
            _mark_cap_stop("agent", str(refused))
            raise AgentError(f"cap: {refused}") from None
        reply, state = super().generate_next_message(message, state)
        _charge_message(ledger, _price(plan, self.llm), reply, reserve)
        return reply, state


class CappedUserSimulator(UserSimulator):
    def generate_next_message(self, message, state):
        plan, ledger = _config()
        reserve = float(plan["tau2"]["call_reserve_usd"])
        try:
            ledger.admit(reserve)
        except CapReached as refused:
            _mark_cap_stop("user", str(refused))
            raise
        reply, state = super().generate_next_message(message, state)
        _charge_message(ledger, _price(plan, self.llm), reply, reserve)
        return reply, state


class PrismTau2Agent(HalfDuplexAgent):
    """One driver run per simulation; tool calls go back to tau2 one per message."""

    def __init__(self, tools, domain_policy: str, task: Any = None, **_: Any):
        super().__init__(tools=tools, domain_policy=domain_policy)
        self.task_id = str(getattr(task, "id", "task"))
        with _counter_lock:
            _counter["n"] += 1
            self.run_no = _counter["n"]

    def get_init_state(self, message_history: Optional[list] = None) -> dict[str, Any]:
        return {"session": None, "pending": None, "cost": 0.0, "usage": {}, "ended": None}

    def _open(self, state: dict[str, Any], instruction: str) -> None:
        plan, ledger = _config()
        tau = plan["tau2"]
        model = plan["model"]
        cap = min(float(tau["per_simulation_cap_usd"]), ledger.remaining())
        if cap <= float(tau["call_reserve_usd"]):
            _mark_cap_stop("agent", "the arm cap is spent")
            raise AgentError("cap: the arm cap is spent")
        # Everything that can fail before the driver is running is done first, and
        # a driver that was started is closed on any failure: tau2 retries a
        # simulation, and each retry would otherwise leave a driver waiting.
        tools = [function_schema(t) for t in self.tools]
        system_prompt = SYSTEM_PROMPT.format(domain_policy=self.domain_policy, agent_instruction=AGENT_INSTRUCTION)
        session = DriverSession(json.loads(os.environ["PRISM_BENCH_DRIVER"]), key_vars=("ANTHROPIC_API_KEY", "ANTHROPIC_URL"),
                                stderr_path=Path(os.environ.get("PRISM_BENCH_RECORDS", ".")) / f"driver-{self.run_no}.stderr.log")
        try:
            session.open()
            session.send("start", **start_message(
                run_id=f"tau2.{self.task_id}.{self.run_no}", task_id=f"tau2.{self.task_id}", instruction=instruction,
                tools=tools, system_prompt=system_prompt,
                model={"provider": model["provider"], "id": model["id"], "provider_options": model.get("provider_options") or {},
                       "reasoning_effort": model["reasoning_effort"]},
                max_cost_usd=cap, max_steps=int(tau["max_steps"]), max_output_tokens_per_call=int(tau["max_output_tokens_per_call"]),
                pricing=plan["prices"][model["provider"]][model["id"]] | {"toolOverheadTokens": int(tau["tool_overhead_tokens"])},
            ))
        except BaseException:
            session.close(timeout=5)
            raise
        state["session"], state["ledger"] = session, ledger

    def generate_next_message(self, message, state):
        if isinstance(message, MultiToolMessage):
            raise AgentError("prism_harness sends one tool call per message; a MultiToolMessage cannot answer it")
        session: DriverSession | None = state["session"]
        if isinstance(message, UserMessage):
            if session is None:
                self._open(state, message.content or "")
            else:
                state["session"].send("user", text=message.content or "(no reply)")
        elif isinstance(message, ToolMessage):
            if session is None or message.id != state["pending"]:
                raise AgentError("a tool result arrived for a call that is not pending")
            state["session"].send("tool_result", call_id=message.id, output=message.content or "", is_error=bool(message.error))
            state["pending"] = None
        return self._pump(state), state

    def _pump(self, state: dict[str, Any]) -> AssistantMessage:
        session: DriverSession = state["session"]
        ledger: Ledger = state["ledger"]
        while True:
            try:
                event = session.next()
            except DriverError as error:
                raise AgentError(f"driver: {error}") from None
            kind = event["type"]
            if kind == "usage":
                state["cost"] += float(event["charged_usd"])
                for key in ("input_tokens", "cache_write_tokens", "cache_read_tokens", "output_tokens"):
                    state["usage"][key] = state["usage"].get(key, 0) + int(event[key])
                ledger.charge(float(event["charged_usd"]))
            elif kind == "tool_call":
                if ledger.remaining() <= 0:
                    session.send("abort", reason="the arm's cost cap is spent")
                    continue
                state["pending"] = event["call_id"]
                return self._message(state, tool_calls=[ToolCall(id=event["call_id"], name=event["name"],
                                                                 arguments=event.get("arguments") or {}, requestor="assistant")])
            elif kind == "reply":
                return self._message(state, content=str(event.get("text") or "") or "(no reply)")
            elif kind == "end":
                state["ended"] = event
                session.close()
                if event.get("status") in ("budget_stopped", "aborted"):
                    _mark_cap_stop("agent", str(event.get("status")))
                raise AgentError(f"driver ended: {event.get('status')} {event.get('code') or ''}".strip())
            elif kind != "approval":
                raise AgentError(f"unexpected driver message {kind!r}")

    def _message(self, state: dict[str, Any], **fields: Any) -> AssistantMessage:
        # tau2 sums each message's cost; a message whose model call has not
        # settled yet carries 0 and the next one carries it, so the total holds.
        usage = state["usage"]
        message = AssistantMessage(role="assistant", cost=round(state["cost"], 8), usage={
            "prompt_tokens": usage.get("input_tokens", 0) + usage.get("cache_write_tokens", 0) + usage.get("cache_read_tokens", 0),
            "completion_tokens": usage.get("output_tokens", 0),
            "fresh_input_tokens": usage.get("input_tokens", 0), "cache_write_tokens": usage.get("cache_write_tokens", 0),
            "cache_read_tokens": usage.get("cache_read_tokens", 0),
        }, **fields)
        state["cost"], state["usage"] = 0.0, {}
        return message

    def stop(self, message=None, state=None) -> None:
        if state and state.get("session") is not None and state.get("ended") is None:
            try:
                state["session"].send("close")
                while state["session"].next()["type"] != "end":
                    pass
            except DriverError:
                pass
            state["session"].close()


def register() -> None:
    from tau2.registry import registry

    registry.register_agent_factory(lambda tools, domain_policy, **kw: PrismTau2Agent(tools, domain_policy, **kw), "prism_harness")
    registry.register_agent_factory(
        lambda tools, domain_policy, **kw: CappedLLMAgent(tools=tools, domain_policy=domain_policy, llm=kw.get("llm"), llm_args=kw.get("llm_args")),
        "capped_llm_agent")
    registry.register_user(CappedUserSimulator, "capped_user_simulator")
