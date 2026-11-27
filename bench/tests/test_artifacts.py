"""The write job consumes only validated metrics. Every tampering below must be refused."""

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from prism_bench import artifacts  # noqa: E402
from test_plan_metrics import sample_plan  # noqa: E402


def good_row(task="task-a", arm="prism", bench="terminal_bench", **over):
    row = {"benchmark": bench, "arm": arm, "task_id": task, "trial": 0, "status": "solved", "reward": 1.0,
           "tokens": {"fresh_input": 10, "cache_write": 0, "cache_read": 5, "output": 3}, "cost_usd": 0.5, "steps": 4,
           "wall_seconds": 12.0, "driver_end": "completed"}
    row.update(over)
    return row


class ArtifactValidationTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.plan = sample_plan()  # shards: 2 -> task-a in shard 0, task-b in shard 1

    def tearDown(self):
        self.tmp.cleanup()

    def put(self, name, rows=None, ledger=None, extra=None):
        d = self.root / name
        d.mkdir()
        if rows is not None:
            (d / "trials.jsonl").write_text("".join(json.dumps(r) + "\n" for r in rows), encoding="utf-8")
        if ledger is not None:
            (d / "ledger.json").write_text(json.dumps(ledger), encoding="utf-8")
        if extra:
            (d / extra).write_text("x", encoding="utf-8")
        return d

    def refuses(self):
        with self.assertRaises(artifacts.ArtifactRefused):
            artifacts.validate(self.root, self.plan)

    def test_valid_metrics_pass_and_their_digests_are_recorded(self):
        self.put("bench-tb-prism-0-metrics", [good_row(extra_field="dropped")], {"cap_usd": 22.5, "spent_usd": 0.5, "calls": 4, "overrun_usd": 0})
        checked = artifacts.validate(self.root, self.plan)
        self.assertEqual(len(checked["rows"]), 1)
        self.assertNotIn("extra_field", checked["rows"][0], "only allow-listed fields reach the signed results")
        self.assertTrue(checked["inputs"]["bench-tb-prism-0-metrics/trials.jsonl"].startswith("sha256:"))
        self.assertIn("bench-tau2-prism-metrics", checked["missing"])

    def test_an_unexpected_artifact_is_refused(self):
        self.put("bench-tb-prism-9-metrics", [good_row()])
        self.refuses()

    def test_an_extra_file_in_a_metrics_artifact_is_refused(self):
        self.put("bench-tb-prism-0-metrics", [good_row()], extra="payload.sh")
        self.refuses()

    def test_a_row_for_another_arm_or_task_is_refused(self):
        self.put("bench-tb-prism-0-metrics", [good_row(arm="terminus-2")])
        self.refuses()

    def test_a_task_outside_its_shard_is_refused(self):
        self.put("bench-tb-prism-0-metrics", [good_row(task="task-b")])
        self.refuses()

    def test_bad_numbers_and_statuses_are_refused(self):
        for bad in (good_row(cost_usd=-1), good_row(cost_usd=float("nan")), good_row(cost_usd=1e9), good_row(status="solved!"),
                    good_row(reward=2.0), good_row(steps=True), good_row(tokens={"output": -1}), good_row(task_id="../x")):
            with self.subTest(bad=str(bad)[:60]):
                for child in self.root.iterdir():
                    for f in child.iterdir():
                        f.unlink()
                    child.rmdir()
                self.put("bench-tb-prism-0-metrics", [bad])
                self.refuses()

    def test_duplicates_are_refused(self):
        self.put("bench-tb-prism-0-metrics", [good_row(), good_row()])
        self.refuses()

    def test_a_ledger_with_another_cap_is_refused(self):
        self.put("bench-tb-prism-0-metrics", [good_row()], {"cap_usd": 100.0, "spent_usd": 0.5})
        self.refuses()

    def test_a_symlinked_file_is_refused(self):
        d = self.put("bench-tb-prism-0-metrics")
        with tempfile.TemporaryDirectory() as outside:
            target = Path(outside) / "trials.jsonl"
            target.write_text(json.dumps(good_row()) + "\n", encoding="utf-8")
            try:
                (d / "trials.jsonl").symlink_to(target)
            except (OSError, NotImplementedError):
                self.skipTest("symlinks need privileges on this platform")
            self.refuses()

if __name__ == "__main__":
    unittest.main()
