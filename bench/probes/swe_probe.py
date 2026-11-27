"""Free disk probe: can SWE-bench Lite grading fit a GitHub-hosted runner? No model call.

Grades the GOLD patch of a few Lite instances, one at a time, with
`--cache_level none` and the prebuilt `swebench/sweb.eval.x86_64.*` images,
pruning Docker between instances, and records peak disk. This answers a
question for later; SWE-bench is not part of the calibration.

    python bench/probes/swe_probe.py --out DIR INSTANCE_ID...
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import threading
import time
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("instances", nargs="+")
    args = parser.parse_args()
    out = Path(args.out).resolve()
    out.mkdir(parents=True, exist_ok=True)
    rows = []
    for instance in args.instances:
        subprocess.run(["docker", "system", "prune", "-af"], capture_output=True)
        before = shutil.disk_usage("/").used
        peak = {"used": before}
        stop = threading.Event()

        def sample() -> None:
            while not stop.is_set():
                peak["used"] = max(peak["used"], shutil.disk_usage("/").used)
                stop.wait(5)

        threading.Thread(target=sample, daemon=True).start()
        started = time.monotonic()
        run_id = "g393probe"
        proc = subprocess.run([sys.executable, "-m", "swebench.harness.run_evaluation", "--dataset_name", "princeton-nlp/SWE-bench_Lite",
                               "--predictions_path", "gold", "--instance_ids", instance, "--max_workers", "1",
                               "--cache_level", "none", "--namespace", "swebench", "--run_id", run_id, "--timeout", "1800"],
                              capture_output=True, text=True, cwd=out, timeout=3600)
        stop.set()
        (out / f"{instance}.log").write_text(proc.stdout + "\n--- stderr ---\n" + proc.stderr, encoding="utf-8")
        report = next(iter(sorted(out.glob(f"*.{run_id}.json"))), None)
        resolved = None
        if report is not None:
            data = json.loads(report.read_text())
            resolved = instance in (data.get("resolved_ids") or [])
            report.rename(out / f"{instance}.report.json")
        rows.append({"instance": instance, "exit_code": proc.returncode, "resolved_gold": resolved,
                     "seconds": round(time.monotonic() - started, 1),
                     "disk_total_gb": round(shutil.disk_usage("/").total / 1024**3, 2),
                     "disk_before_gb": round(before / 1024**3, 2), "disk_peak_gb": round(peak["used"] / 1024**3, 2),
                     "disk_delta_gb": round((peak["used"] - before) / 1024**3, 2)})
    summary = {"instances": rows, "fits": all(r["resolved_gold"] for r in rows),
               "max_disk_delta_gb": max(r["disk_delta_gb"] for r in rows)}
    (out / "swe-probe.json").write_text(json.dumps(summary, indent=1), encoding="utf-8")
    print(json.dumps(summary, indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
