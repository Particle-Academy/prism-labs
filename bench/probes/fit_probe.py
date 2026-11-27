"""Free fit probe for one Terminal-Bench task on a GitHub-hosted runner. No model call.

Runs Harbor's `nop` agent on the task: the environment is built and started
and the verifier runs, so what is measured is everything a real trial needs
from the runner except the agent. Samples disk and memory every 5 s.

A task FITS when Harbor finished without an exception, the verifier produced
a reward (any value; nop is expected to fail the task), and peak disk and
memory stayed inside the runner with a margin. Fit is the only criterion: the
subset is chosen from fitting tasks by a fixed hash order, never by difficulty.

    python bench/probes/fit_probe.py --task NAME --dataset ID --out DIR
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import threading
import time
from pathlib import Path

DISK_MARGIN = 1 * 1024**3
MEM_MARGIN = 512 * 1024**2


def mem_available() -> tuple[int, int]:
    info = {}
    for line in Path("/proc/meminfo").read_text().splitlines():
        key, value = line.split(":", 1)
        info[key] = int(value.split()[0]) * 1024
    return info["MemTotal"], info["MemAvailable"]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--task", required=True)
    parser.add_argument("--dataset", required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--timeout", type=int, default=6000)
    args = parser.parse_args()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    disk_total = shutil.disk_usage("/").total
    disk_before = shutil.disk_usage("/").used
    mem_total, _ = mem_available()
    peak = {"disk_used": disk_before, "mem_used": 0}
    stop = threading.Event()

    def sample() -> None:
        while not stop.is_set():
            peak["disk_used"] = max(peak["disk_used"], shutil.disk_usage("/").used)
            total, available = mem_available()
            peak["mem_used"] = max(peak["mem_used"], total - available)
            stop.wait(5)

    threading.Thread(target=sample, daemon=True).start()
    started = time.monotonic()
    command = ["harbor", "run", "-d", args.dataset, "-a", "nop", "-i", args.task, "-i", f"*/{args.task}", "-n", "1", "-o", str(out / "jobs")]
    try:
        proc = subprocess.run(command, capture_output=True, text=True, timeout=args.timeout)
        exit_code, timed_out = proc.returncode, False
        (out / "harbor.log").write_text((proc.stdout or "") + "\n--- stderr ---\n" + (proc.stderr or ""), encoding="utf-8")
    except subprocess.TimeoutExpired:
        exit_code, timed_out = None, True
    stop.set()
    seconds = round(time.monotonic() - started, 1)

    results = [json.loads(p.read_text()) for p in (out / "jobs").glob("*/*/result.json")] if (out / "jobs").exists() else []
    trial = next((r for r in results if "task_name" in r), None)
    exception = (trial or {}).get("exception_info") or None
    rewards = ((trial or {}).get("verifier_result") or {}).get("rewards")
    images = subprocess.run(["docker", "image", "ls", "--format", "{{.Repository}}:{{.Tag}} {{.Size}}"],
                            capture_output=True, text=True).stdout.splitlines()
    record = {
        "task": args.task, "dataset": args.dataset, "exit_code": exit_code, "timed_out": timed_out, "seconds": seconds,
        "trial_found": trial is not None, "exception_type": (exception or {}).get("exception_type"),
        "exception_message": ((exception or {}).get("exception_message") or "")[:500],
        "verifier_ran": rewards is not None, "rewards": rewards,
        "disk_total_gb": round(disk_total / 1024**3, 2), "disk_before_gb": round(disk_before / 1024**3, 2),
        "disk_peak_gb": round(peak["disk_used"] / 1024**3, 2), "mem_total_gb": round(mem_total / 1024**3, 2),
        "mem_peak_gb": round(peak["mem_used"] / 1024**3, 2), "images": images,
    }
    record["fits"] = bool(
        exit_code == 0 and not timed_out and trial is not None and exception is None and rewards is not None
        and peak["disk_used"] < disk_total - DISK_MARGIN and peak["mem_used"] < mem_total - MEM_MARGIN
    )
    (out / "probe.json").write_text(json.dumps(record, indent=1), encoding="utf-8")
    print(json.dumps(record, indent=1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
