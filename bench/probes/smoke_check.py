"""Fail the smoke job unless every arm produced a trial that is not an adapter error.

    python bench/probes/smoke_check.py SMOKE_DIR
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
expected = ["tb-prism", "tb-terminus-2", "tau2-prism", "tau2-llm_agent"]
failed = False
for arm in expected:
    path = root / arm / "trials.jsonl"
    rows = [json.loads(l) for l in path.read_text(encoding="utf-8").splitlines() if l.strip()] if path.exists() else []
    good = [r for r in rows if r.get("status") not in ("error", "not_run")]
    print(f"{arm}: {len(rows)} trial(s), statuses {[r.get('status') for r in rows]}, driver_end {[r.get('driver_end') for r in rows]}")
    if not good:
        print(f"::error::smoke: {arm} produced no usable trial")
        failed = True
sys.exit(1 if failed else 0)
