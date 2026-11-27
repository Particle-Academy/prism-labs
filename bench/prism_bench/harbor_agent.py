"""Harbor agents for the Terminal-Bench arms (Harbor v0.27.0).

- `PrismHarnessAgent`: the prism-harness arm. A `BaseAgent` (the loop runs
  outside the container), which starts the PHP driver and runs each `bash` call
  through `environment.exec()` inside the task container.
- `CappedTerminus2`: the neutral arm. Terminus-2 exactly as Harbor ships it,
  with one addition: before every model call the arm ledger must admit the
  call's worst case, and after it the call is charged. Its behaviour is
  otherwise unchanged; the same dollar cap applies to both arms.

Both read their configuration from the environment the runner sets
(`prism_bench.cli run-tb`), never from task input:

    PRISM_BENCH_PLAN          the frozen plan file
    PRISM_BENCH_ARM_LEDGER    this shard's ledger file
    PRISM_BENCH_ARM_CAP       this shard's cap in USD
    PRISM_BENCH_DRIVER        the driver command, a JSON list (prism arm only)
    PRISM_BENCH_TASK          the task this Harbor invocation runs (one per invocation)
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

from harbor.agents.base import BaseAgent
from harbor.agents.terminus_2.terminus_2 import Terminus2
from harbor.environments.base import BaseEnvironment
from harbor.models.agent.context import AgentContext

from . import plan as plans
from .driver import DriverSession, start_message
from .ledger import CapReached, Ledger
from .prices import conservative_cost_usd
from .trial import TrialRecorder, run_driver_trial

BASH_TOOL = {
    "name": "bash",
    "description": "Run a bash command in the task's terminal and return its combined stdout, stderr and exit code. "
                   "Each call is a fresh shell in the task's working directory; chain commands with && when state matters.",
    "parameters": {"type": "object", "properties": {"command": {"type": "string", "description": "The command to run."}},
                   "required": ["command"]},
}
COMMAND_TIMEOUT_SEC = 900


class ArmStop(BaseException):
    """Raised inside Terminus-2 when the cap refuses a call.

    A BaseException ON PURPOSE. Terminus-2 catches `Exception` around its
    summarisation fallbacks (and then carries on with a canned reply) and its
    LLM query retries on any `Exception`. An ordinary exception would be
    swallowed there, and the loop would spin on refused calls until the agent
    timeout. Like asyncio.CancelledError, this one passes through both.
    """


def _config() -> tuple[dict[str, Any], Ledger]:
    plan = plans.load(Path(os.environ["PRISM_BENCH_PLAN"]))
    ledger = Ledger(Path(os.environ["PRISM_BENCH_ARM_LEDGER"]), float(os.environ["PRISM_BENCH_ARM_CAP"]))
    return plan, ledger


def _write(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=1, ensure_ascii=False), encoding="utf-8")


class PrismHarnessAgent(BaseAgent):
    SUPPORTS_ATIF = True

    @staticmethod
    def name() -> str:
        return "prism-harness-php"

    def version(self) -> str | None:
        return os.environ.get("PRISM_BENCH_HARNESS_VERSION")

    async def setup(self, environment: BaseEnvironment) -> None:
        return None

    async def run(self, instruction: str, environment: BaseEnvironment, context: AgentContext) -> None:
        plan, ledger = _config()
        tb = plan["terminal_bench"]
        model = plan["model"]
        task_id = os.environ["PRISM_BENCH_TASK"]
        recorder = TrialRecorder(benchmark="terminal_bench", arm="prism", task_id=task_id, trial=0, model=model["id"],
                                 reasoning_effort=model["reasoning_effort"], agent_name=self.name(),
                                 agent_version=self.version() or "unknown", tool_definitions=[BASH_TOOL])
        cap = min(float(tb["per_task_cap_usd"]), ledger.remaining())
        if cap < float(tb["min_task_reserve_usd"]):
            recorder.status = "not_run"
            self._finish(recorder, context)
            return

        session = DriverSession(json.loads(os.environ["PRISM_BENCH_DRIVER"]), key_vars=("ANTHROPIC_API_KEY", "ANTHROPIC_URL"),
                                stderr_path=Path(str(self.logs_dir)) / "driver.stderr.log")
        hello = session.open()
        recorder.agent_version = str(hello["driver"].get("harness_version", recorder.agent_version))
        start = start_message(
            run_id=f"tb.{task_id}", task_id=task_id, instruction=instruction, tools=[BASH_TOOL],
            model={"provider": model["provider"], "id": model["id"], "provider_options": model.get("provider_options") or {},
                   "reasoning_effort": model["reasoning_effort"]},
            max_cost_usd=cap, max_steps=int(tb["max_steps"]), max_output_tokens_per_call=int(tb["max_output_tokens_per_call"]),
            pricing=plan["prices"][model["provider"]][model["id"]] | {"toolOverheadTokens": int(tb["tool_overhead_tokens"])},
        )

        async def execute(call: dict[str, Any]) -> tuple[str, bool]:
            command = (call.get("arguments") or {}).get("command")
            if not isinstance(command, str) or command == "":
                return "the bash tool needs a non-empty string `command`", True
            result = await environment.exec(command, timeout_sec=COMMAND_TIMEOUT_SEC)
            text = (result.stdout or "") + (("\n[stderr]\n" + result.stderr) if result.stderr else "")
            return f"{text}\n[exit code {result.return_code}]", result.return_code != 0

        try:
            await run_driver_trial(session, start, recorder, ledger, execute)
        finally:
            session.close()
            self._finish(recorder, context)

    def _finish(self, recorder: TrialRecorder, context: AgentContext) -> None:
        tokens = recorder.tokens
        # Harbor's convention: n_input_tokens INCLUDES cached tokens.
        context.n_input_tokens = tokens["fresh_input"] + tokens["cache_write"] + tokens["cache_read"]
        context.n_cache_tokens = tokens["cache_read"]
        context.n_output_tokens = tokens["output"]
        context.cost_usd = round(recorder.cost_usd, 8)
        context.metadata = {"prism_bench": recorder.record(reward=None, solved=None)}
        logs = Path(str(self.logs_dir))
        _write(logs / "prism-trial.json", recorder.record(reward=None, solved=None))
        _write(logs / "trajectory.json", recorder.atif(session_id=f"tb.{recorder.task_id}"))


class CappedTerminus2(Terminus2):
    """Terminus-2, unchanged, under the same per-arm dollar cap as the prism arm."""

    @staticmethod
    def name() -> str:
        return "terminus-2"

    async def run(self, instruction: str, environment: BaseEnvironment, context: AgentContext) -> None:
        plan, ledger = _config()
        tb = plan["terminal_bench"]
        model = plan["model"]
        entry = plan["prices"][model["provider"]][model["id"]]
        reserve = float(tb["neutral_call_reserve_usd"])
        logs = Path(str(self.logs_dir))
        task_budget = {"spent": 0.0, "cap": min(float(tb["per_task_cap_usd"]), ledger.remaining())}
        if task_budget["cap"] < float(tb["min_task_reserve_usd"]):
            _write(logs / "prism-bench-status.json", {"status": "not_run"})
            return
        original = self._llm.call

        async def capped_call(*args: Any, **kwargs: Any):
            if task_budget["spent"] + reserve > task_budget["cap"]:
                raise ArmStop("the trial's cost cap cannot admit another call")
            try:
                ledger.admit(reserve)
            except CapReached as refused:
                raise ArmStop(str(refused)) from None
            response = await original(*args, **kwargs)
            usage = getattr(response, "usage", None)
            charge = reserve if usage is None else conservative_cost_usd(
                entry, prompt_including_cache=usage.prompt_tokens, cache_hits=usage.cache_tokens,
                output=usage.completion_tokens, reported_usd=usage.cost_usd)
            task_budget["spent"] += charge
            ledger.charge(charge)
            return response

        self._llm.call = capped_call
        status = "graded"
        try:
            await super().run(instruction, environment, context)
        except ArmStop:
            status = "cap_stopped"
        finally:
            _write(logs / "prism-bench-status.json", {"status": status, "charged_usd": round(task_budget["spent"], 8)})
