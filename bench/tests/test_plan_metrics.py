"""The dispatch gate, the estimate, the ledger, the metrics and the scrub. Standard library only."""

import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from prism_bench import metrics, plan as plans, scrub  # noqa: E402
from prism_bench.ledger import CapReached, Ledger  # noqa: E402
from prism_bench.prices import conservative_cost_usd, cost_usd  # noqa: E402

HAIKU = {"inputPerMTok": 0.1, "outputPerMTok": 0.5, "cacheReadPerMTok": 0.01, "cacheWritePerMTok": 0.125,
         "tiers": [{"aboveInputTokens": 100000, "inputPerMTok": 0.5, "outputPerMTok": 2.5, "cacheReadPerMTok": 0.05,
                    "cacheWritePerMTok": 0.625}]}


def sample_plan():
    tasks = ["task-a", "task-b"]
    return {
        "id": "test", "cap_usd": 100.0,
        "model": {"provider": "anthropic", "id": "claude-haiku-5-5", "reasoning_effort": "default"},
        "harness": {"version": "0.26.9"},
        "prices": {"anthropic": {"claude-haiku-5-5": HAIKU}},
        "terminal_bench": {"tasks": tasks, "subset_digest": plans.subset_digest(tasks), "attempts": 1, "shards": 2,
                           "estimate": {"per_try_usd": 3.0, "source": "board-derived"},
                           "arms": {"prism": {"cap_usd": 45.0}, "terminus-2": {"cap_usd": 45.0}}},
        "tau2": {"num_tasks": 20, "num_trials": 4, "user_llm": "openai/gpt-6.1-terra",
                 "estimate": {"per_simulation_usd": 0.05, "source": "unmeasured"},
                 "arms": {"prism": {"cap_usd": 5.0}, "llm_agent": {"cap_usd": 5.0}}},
    }


class DispatchGateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        (self.root / "evals" / "plans").mkdir(parents=True)
        (self.root / "evals" / "approvals").mkdir(parents=True)
        self.write_plan(sample_plan())

    def tearDown(self):
        self.tmp.cleanup()

    def write_plan(self, plan):
        (self.root / "evals/plans/g393-test.json").write_text(json.dumps(plan), encoding="utf-8")
        self.digest = plans.digest(plan)

    def approve(self, **over):
        approval = {"ftq_id": "q_abc123", "plan": "evals/plans/g393-test.json", "plan_digest": self.digest,
                    "cap_usd": 100.0, "approved_by": "Wish", "single_use": True, **over}
        (self.root / "evals/approvals/q_abc123.json").write_text(json.dumps(approval), encoding="utf-8")

    def check(self, **over):
        args = {"ftq_id": "q_abc123", "plan_digest": self.digest, "cap_usd": "100", **over}
        return plans.check_dispatch(self.root, **args)

    def refuses(self, **over):
        with self.assertRaises(plans.PlanRefused):
            self.check(**over)

    def test_a_matching_approval_passes(self):
        self.approve()
        self.assertEqual(self.check()["estimate"]["worst_case_usd"], 100.0)

    def test_no_approval_file_refuses(self):
        self.refuses()

    def test_mismatched_inputs_refuse(self):
        self.approve()
        self.refuses(cap_usd="150")
        self.refuses(cap_usd="abc")
        self.refuses(plan_digest="sha256:" + "0" * 64)
        self.refuses(ftq_id="q_abc124")
        self.refuses(ftq_id="../q_abc123")

    def test_an_edited_plan_refuses(self):
        self.approve()
        edited = sample_plan()
        edited["terminal_bench"]["arms"]["prism"]["cap_usd"] = 44.0
        (self.root / "evals/plans/g393-test.json").write_text(json.dumps(edited), encoding="utf-8")
        self.refuses()

    def test_a_used_approval_refuses(self):
        self.approve()
        (self.root / "evals/results/q_abc123").mkdir(parents=True)
        self.refuses()

    def test_an_approval_not_marked_single_use_refuses(self):
        self.approve(single_use=False)
        self.refuses()

    def test_a_plan_outside_evals_plans_refuses(self):
        self.approve(plan="../etc/plan.json")
        self.refuses()

    def test_arm_caps_above_the_cap_refuse(self):
        plan = sample_plan()
        plan["tau2"]["arms"]["prism"]["cap_usd"] = 6.0
        self.write_plan(plan)
        self.approve()
        self.refuses()

    def test_a_retired_model_refuses(self):
        plan = sample_plan()
        plan["model"]["id"] = "claude-haiku-4-5"
        self.write_plan(plan)
        self.approve()
        self.refuses()

    def test_a_tampered_subset_refuses(self):
        plan = sample_plan()
        plan["terminal_bench"]["tasks"] = ["task-a", "task-c"]
        self.write_plan(plan)
        self.approve()
        self.refuses()


class LedgerAndPriceTest(unittest.TestCase):
    def test_the_ledger_admits_only_what_fits_and_is_shared_through_its_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            ledger = Ledger(Path(tmp) / "l.json", 1.0)
            ledger.admit(1.0)
            ledger.charge(0.75)
            with self.assertRaises(CapReached):
                ledger.admit(0.3)
            self.assertEqual(Ledger(Path(tmp) / "l.json", 1.0).spent(), 0.75)
            with self.assertRaises(ValueError):
                Ledger(Path(tmp) / "l.json", 2.0).spent()

    def test_tiered_price_and_conservative_cache_writes(self):
        self.assertAlmostEqual(cost_usd(HAIKU, fresh=1000, cache_write=0, cache_read=0, output=1000), 0.0006)
        self.assertAlmostEqual(cost_usd(HAIKU, fresh=200000, cache_write=0, cache_read=0, output=0), 0.1)
        self.assertAlmostEqual(conservative_cost_usd(HAIKU, prompt_including_cache=1000, cache_hits=0, output=0), 0.000125)
        self.assertEqual(conservative_cost_usd(HAIKU, prompt_including_cache=10, cache_hits=0, output=0, reported_usd=1.0), 1.0)


def row(arm, task, status, cost, bench="terminal_bench", cache_read=0):
    return {"benchmark": bench, "arm": arm, "task_id": task, "trial": 0, "status": status, "cost_usd": cost, "steps": 2,
            "wall_seconds": 10, "tokens": {"fresh_input": 100, "cache_write": 0, "cache_read": cache_read, "output": 10}}


class MetricsTest(unittest.TestCase):
    def test_usd_per_solved_counts_failed_and_stopped_attempts(self):
        s = metrics.summarise([row("prism", "a", "solved", 1.0), row("prism", "b", "failed", 1.0),
                               row("prism", "c", "cap_stopped", 2.0)])
        self.assertEqual((s["usd_per_solved"], s["attempts"], s["score"]), (4.0, 3, 0.3333))

    def test_nothing_solved_has_no_usd_per_solved(self):
        self.assertIsNone(metrics.summarise([row("prism", "a", "failed", 1.0)])["usd_per_solved"])

    def test_not_run_is_not_an_attempt(self):
        s = metrics.summarise([row("prism", "a", "solved", 1.0), row("prism", "b", "not_run", 0.0)])
        self.assertEqual((s["attempts"], s["not_run"], s["score"]), (1, 1, 1.0))

    def test_cache_read_share(self):
        self.assertEqual(metrics.summarise([row("prism", "a", "solved", 1.0, cache_read=300)])["cache_read_share"], 0.75)

    def test_the_delta_uses_only_tasks_both_arms_attempted(self):
        out = metrics.compare([row("prism", "a", "solved", 1.0), row("prism", "b", "solved", 1.0),
                               row("terminus-2", "a", "failed", 3.0), row("terminus-2", "b", "not_run", 0.0)])
        d = out["terminal_bench"]["deltas"]["prism_vs_terminus-2"]
        self.assertEqual(d["shared_tasks"], ["a"])
        self.assertEqual(d["delta"]["score"], 1.0)
        self.assertEqual(d["delta"]["usd_per_attempt"], -2.0)

    def test_pass_hat_k(self):
        rows = [dict(row("prism", "a", s, 0.1, bench="tau2"), trial=i)
                for i, s in enumerate(["solved", "solved", "failed", "solved"])]
        self.assertEqual(metrics.pass_hat_k(rows), {1: 0.75, 2: 0.5, 3: 0.25, 4: 0.0})


class ScrubTest(unittest.TestCase):
    def test_it_fails_on_a_key_and_never_prints_it(self):
        secret = "sk-ant-api03-" + "A" * 40
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "log.txt").write_text("header x-api-key: " + secret + "\n", encoding="utf-8")
            buffer = io.StringIO()
            with redirect_stdout(buffer):
                code = scrub.main([tmp])
        self.assertEqual(code, 1)
        self.assertNotIn(secret, buffer.getvalue())
        self.assertIn("anthropic-key", buffer.getvalue())

    def test_clean_artifacts_pass(self):
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "trials.jsonl").write_text(json.dumps(row("prism", "a", "solved", 1.0)), encoding="utf-8")
            with redirect_stdout(io.StringIO()):
                self.assertEqual(scrub.main([tmp]), 0)


if __name__ == "__main__":
    unittest.main()
