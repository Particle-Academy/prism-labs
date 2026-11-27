"""The adapter's own spend account: the OUTER cap, per arm and per shard.

The harness enforces its per-call cap inside the driver; this enforces the
arm's cap outside it, from reported usage. Two independent caps, because a cap
enforced only by the process spending the money is enforced by the party with
the bug. For an arm whose agent the adapter does not drive (Terminus-2, tau2's
built-in agent, the tau2 user simulator) this is the only cap, checked before
every model call and charged after it.

A ledger is a JSON file, so successive trials in one shard (separate Harbor
invocations, one task each) share it. It is written atomically.
"""

from __future__ import annotations

import json
import os
import tempfile
import threading
from dataclasses import dataclass
from pathlib import Path


class CapReached(RuntimeError):
    """The next call or trial would cross a cap. Recorded as cap_stopped, never solved."""


@dataclass
class Ledger:
    path: Path
    cap_usd: float

    _lock = threading.Lock()

    def __post_init__(self) -> None:
        if not (self.cap_usd > 0):
            raise ValueError("a ledger needs a positive cap")
        self.path = Path(self.path)

    def _read(self) -> dict:
        if not self.path.exists():
            return {"cap_usd": self.cap_usd, "spent_usd": 0.0, "calls": 0, "overrun_usd": 0.0}
        state = json.loads(self.path.read_text(encoding="utf-8"))
        if abs(float(state["cap_usd"]) - self.cap_usd) > 1e-9:
            raise ValueError("this ledger file was opened with a different cap")
        return state

    def _write(self, state: dict) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=self.path.parent, prefix=".ledger-")
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(state, handle)
        os.replace(tmp, self.path)

    def spent(self) -> float:
        with self._lock:
            return float(self._read()["spent_usd"])

    def remaining(self) -> float:
        return max(0.0, self.cap_usd - self.spent())

    def admit(self, reserve_usd: float) -> None:
        """Refuse when the reserve for the next call or trial does not fit."""
        if reserve_usd < 0:
            raise ValueError("a reserve cannot be negative")
        if self.spent() + reserve_usd > self.cap_usd + 1e-12:
            raise CapReached(f"cap ${self.cap_usd:.2f} cannot admit ${reserve_usd:.4f} more (spent ${self.spent():.4f})")

    def charge(self, usd: float) -> float:
        """Record actual spend. Returns the new total. An overrun is kept, never hidden."""
        if usd < 0 or usd != usd:
            raise ValueError("a charge must be a non-negative number")
        with self._lock:
            state = self._read()
            state["spent_usd"] = float(state["spent_usd"]) + usd
            state["calls"] = int(state["calls"]) + 1
            state["overrun_usd"] = max(0.0, state["spent_usd"] - self.cap_usd)
            self._write(state)
            return state["spent_usd"]
