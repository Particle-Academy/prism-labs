"""The adapter side of prism-bench-driver/1 (prism-parity specs/bench-driver.md).

A DriverSession starts a harness driver as a subprocess and speaks the protocol
to it. It never executes anything the driver asks for: the caller does, in the
benchmark's own sandbox, and answers with tool_result.

Credentials: the provider key reaches the child through its environment, by
variable NAME, copied from this process's environment. Nothing here reads the
value into a message, a log or a return value.
"""

from __future__ import annotations

import json
import os
import queue
import subprocess
import threading
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Iterable

PROTOCOL = "prism-bench-driver/1"
MAX_LINE_BYTES = 8 * 1024 * 1024

# The child gets these and nothing else from our environment, plus the provider
# key variables it is explicitly given. A benchmark job's environment holds
# other secrets (the second provider's key, the runner token); a driver that
# inherits everything holds them too.
_BASE_ENV = ("PATH", "HOME", "SYSTEMROOT", "TEMP", "TMP", "TMPDIR", "LANG", "LC_ALL", "COMPOSER_HOME")


class DriverError(RuntimeError):
    """The driver broke the protocol, died, or stopped answering."""


@dataclass
class DriverSession:
    command: list[str]
    key_vars: Iterable[str] = ()
    stderr_path: Path | None = None
    read_timeout: float = 900.0
    extra_env: dict[str, str] = field(default_factory=dict)
    _proc: subprocess.Popen | None = None
    _lines: "queue.Queue[bytes | None]" = field(default_factory=queue.Queue)
    _stderr: Any = None
    hello: dict[str, Any] | None = None

    def open(self) -> dict[str, Any]:
        env = {k: os.environ[k] for k in _BASE_ENV if k in os.environ}
        for name in self.key_vars:
            if name in os.environ:
                env[name] = os.environ[name]
        env.update(self.extra_env)
        self._stderr = open(self.stderr_path, "ab") if self.stderr_path else subprocess.DEVNULL
        self._proc = subprocess.Popen(
            self.command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=self._stderr, env=env, bufsize=0,
        )
        threading.Thread(target=self._pump, daemon=True).start()
        hello = self.next()
        if hello.get("type") != "hello" or hello.get("protocol") != PROTOCOL:
            raise DriverError("the driver did not open with a prism-bench-driver/1 hello")
        self.hello = hello
        return hello

    def _pump(self) -> None:
        assert self._proc is not None and self._proc.stdout is not None
        out = self._proc.stdout
        while True:
            line = out.readline(MAX_LINE_BYTES + 1)
            if not line:
                self._lines.put(None)
                return
            if len(line) > MAX_LINE_BYTES:
                # Refuse rather than buffer: the rest of this line is unread
                # and the stream cannot be trusted past it.
                self._lines.put(b"\x00oversize")
                return
            self._lines.put(line)

    def send(self, type_: str, **fields: Any) -> None:
        assert self._proc is not None and self._proc.stdin is not None
        line = json.dumps({"v": 1, "type": type_, **fields}, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n"
        if len(line) > MAX_LINE_BYTES:
            raise DriverError(f"refusing to send a {type_} line over {MAX_LINE_BYTES} bytes")
        try:
            self._proc.stdin.write(line)
            self._proc.stdin.flush()
        except (BrokenPipeError, OSError) as error:
            raise DriverError("the driver closed its input") from error

    def next(self) -> dict[str, Any]:
        try:
            line = self._lines.get(timeout=self.read_timeout)
        except queue.Empty as error:
            raise DriverError(f"no driver line within {self.read_timeout:.0f}s") from error
        if line is None:
            raise DriverError("the driver exited without an end line")
        if line == b"\x00oversize":
            raise DriverError(f"the driver wrote a line over {MAX_LINE_BYTES} bytes")
        try:
            message = json.loads(line)
        except ValueError as error:
            raise DriverError("the driver wrote a line that is not JSON (stdout must carry only the protocol)") from error
        if not isinstance(message, dict) or message.get("v") != 1 or not isinstance(message.get("type"), str):
            raise DriverError("the driver wrote a line that is not a v1 message")
        return message

    def close(self, timeout: float = 30.0) -> int | None:
        if self._proc is None:
            return None
        try:
            if self._proc.stdin:
                self._proc.stdin.close()
        except OSError:
            pass
        try:
            code = self._proc.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            self._proc.kill()
            code = self._proc.wait()
        if self._proc.stdout:
            self._proc.stdout.close()
        if self._stderr not in (None, subprocess.DEVNULL):
            self._stderr.close()
        return code


def start_message(*, run_id: str, task_id: str, instruction: str, tools: list[dict[str, Any]], model: dict[str, Any],
                  max_cost_usd: float, max_steps: int, max_output_tokens_per_call: int, pricing: dict[str, Any],
                  system_prompt: str | None = None) -> dict[str, Any]:
    if not (max_cost_usd > 0):
        raise ValueError("a driver run is never uncapped")
    message: dict[str, Any] = {
        "run_id": run_id, "task_id": task_id, "instruction": instruction, "tools": tools, "model": model,
        "limits": {"max_cost_usd": round(max_cost_usd, 6), "max_steps": max_steps,
                   "max_output_tokens_per_call": max_output_tokens_per_call},
        "pricing": pricing, "approval_policy": "auto_grant_all",
    }
    if system_prompt is not None:
        message["system_prompt"] = system_prompt
    return message
