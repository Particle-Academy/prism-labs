"""Write a ONE-task plan for the free smoke job. It is never approved and never used by harness-bench.yml.

    python bench/probes/smoke_plan.py TASK OUT.json
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from prism_bench import plan as plans  # noqa: E402

PRICE = {"inputPerMTok": 0.1, "outputPerMTok": 0.5, "cacheReadPerMTok": 0.01, "cacheWritePerMTok": 0.125}
task, out = sys.argv[1], sys.argv[2]
plan = {
    "id": "smoke", "cap_usd": 1.0,
    "model": {"provider": "anthropic", "id": "claude-haiku-5-5", "reasoning_effort": "default", "provider_options": {}},
    "harness": {"version": "0.26.9", "driver_ref": "0" * 40},
    "prices": {"anthropic": {"claude-haiku-5-5": PRICE}, "openai": {"gpt-6.1-terra": PRICE}},
    "terminal_bench": {"dataset": "terminal-bench/terminal-bench@sha256:5881c1b1c74a96f07baef00a7c869e09802704bc0dc912c870e7acccc11440da", "tasks": [task], "subset_digest": plans.subset_digest([task]),
                       "attempts": 1, "shards": 1, "per_task_cap_usd": 0.2, "min_task_reserve_usd": 0.01,
                       "neutral_call_reserve_usd": 0.01, "max_steps": 6, "max_output_tokens_per_call": 512,
                       "tool_overhead_tokens": 600, "agent_timeout_multiplier": 0.01, "per_task_wall_minutes": 30,
                       "shard_deadline_minutes": 60, "estimate": {"per_try_usd": 0.01, "source": "smoke"},
                       "arms": {"prism": {"cap_usd": 0.3}, "terminus-2": {"cap_usd": 0.3}}},
    "tau2": {"domain": "airline", "num_tasks": 1, "num_trials": 1, "seed": 300, "user_llm": "openai/gpt-6.1-terra",
             "per_simulation_cap_usd": 0.1, "call_reserve_usd": 0.001, "max_steps": 10, "max_output_tokens_per_call": 512,
             "tool_overhead_tokens": 600, "estimate": {"per_simulation_usd": 0.01, "source": "smoke"},
             "arms": {"prism": {"cap_usd": 0.2}, "llm_agent": {"cap_usd": 0.2}}},
}
plans.validate(plan)
Path(out).write_text(json.dumps(plan, indent=1), encoding="utf-8")
print(out)
