"""The adapter side of prism-bench-driver/1, against a fake driver. No provider is called."""

import asyncio
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from prism_bench.driver import DriverError, DriverSession, start_message  # noqa: E402
from prism_bench.ledger import Ledger  # noqa: E402
from prism_bench.trial import TrialRecorder, clip, run_driver_trial  # noqa: E402

FIXTURE = HERE / "fixtures" / "bench-driver-conformance.jsonl"


def fake(*args):
    return [sys.executable, "-I", str(HERE / "fake_driver.py"), *args]


def recorder():
    return TrialRecorder(benchmark="terminal_bench", arm="prism", task_id="hello-world", trial=0, model="claude-haiku-5-5",
                         reasoning_effort="default", agent_name="prism-harness-php", agent_version="test")


def fixture_start():
    lines = [json.loads(l) for l in FIXTURE.read_text(encoding="utf-8").splitlines() if l.strip()]
    s = next(l["msg"] for l in lines if l["msg"]["type"] == "start")
    return start_message(run_id=s["run_id"], task_id=s["task_id"], instruction=s["instruction"], tools=s["tools"],
                         model=s["model"], max_cost_usd=s["limits"]["max_cost_usd"], max_steps=s["limits"]["max_steps"],
                         max_output_tokens_per_call=s["limits"]["max_output_tokens_per_call"], pricing=s["pricing"])


class DriverTrialTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_it_writes_exactly_the_adapter_lines_of_the_parity_fixture(self):
        session = DriverSession(fake("replay", str(FIXTURE)))
        session.open()
        turns = iter(["Thanks. Now say stopped.", None])

        async def execute(call):
            self.assertEqual(call["arguments"], {"command": "ls"})
            return "README.md\nsrc\n", False

        async def next_turn(text):
            return next(turns)

        rec = asyncio.run(run_driver_trial(session, fixture_start(), recorder(), Ledger(self.dir / "l.json", 1.0), execute, next_turn))
        self.assertEqual(session.close(), 0, "the fake driver exits 3 when an adapter line differs from the fixture")
        row = rec.record(reward=1.0, solved=True)
        self.assertEqual(row["status"], "solved")
        self.assertEqual(row["steps"], 3)
        self.assertEqual(row["tokens"], {"fresh_input": 142, "cache_write": 40, "cache_read": 320, "output": 28})
        self.assertAlmostEqual(row["cost_usd"], 0.000364, places=9)
        atif = rec.atif("s")
        agent_steps = [s for s in atif["steps"] if s["source"] == "agent"]
        # The tool call belongs to the FIRST model call (usage follows the calls it asked for).
        self.assertEqual(agent_steps[0]["tool_calls"][0]["function_name"], "bash")
        self.assertEqual(agent_steps[0]["observation"]["results"][0]["source_call_id"], "t1")
        self.assertEqual(agent_steps[1]["message"], "done")
        self.assertEqual([s["source"] for s in atif["steps"]], ["user", "agent", "agent", "user", "agent"])

    def test_a_stopped_run_is_never_solved(self):
        session = DriverSession(fake("spender"))
        session.open()

        async def execute(call):
            return "", False

        rec = asyncio.run(run_driver_trial(session, fixture_start(), recorder(), Ledger(self.dir / "l.json", 1.0), execute))
        session.close()
        self.assertEqual(rec.end["status"], "aborted", "the adapter must abort once the arm's cap is spent")
        self.assertEqual(rec.record(reward=1.0, solved=True)["status"], "cap_stopped")

    def test_the_child_gets_only_named_variables(self):
        os.environ["PRISM_BENCH_TEST_SECRET"] = "not-for-the-driver"
        os.environ["ANTHROPIC_API_KEY_FAKE_NAME"] = "x"
        try:
            session = DriverSession(fake("env"), key_vars=("ANTHROPIC_API_KEY_FAKE_NAME",))
            session.open()
            session.send("start", **fixture_start())
            names = session.next()["code"].split(",")
            session.close()
        finally:
            del os.environ["PRISM_BENCH_TEST_SECRET"]
            del os.environ["ANTHROPIC_API_KEY_FAKE_NAME"]
        self.assertNotIn("PRISM_BENCH_TEST_SECRET", names)
        self.assertIn("ANTHROPIC_API_KEY_FAKE_NAME", names)

    def test_a_stray_stdout_line_is_a_protocol_error(self):
        session = DriverSession(fake("junk"))
        with self.assertRaises(DriverError):
            session.open()
        session.close()

    def test_an_oversize_line_is_refused_not_buffered(self):
        session = DriverSession(fake("huge"))
        with self.assertRaises(DriverError):
            session.open()
        session.close()

    def test_a_driver_run_is_never_uncapped(self):
        for cap in (0, -1, float("nan")):
            with self.assertRaises(ValueError):
                start_message(run_id="r", task_id="t", instruction="i", tools=[], model={}, max_cost_usd=cap,
                              max_steps=1, max_output_tokens_per_call=1, pricing={})

    def test_tool_output_is_clipped_and_says_so(self):
        text = clip("a" * 200_000)
        self.assertLess(len(text.encode()), 70_000)
        self.assertIn("bytes omitted by the benchmark adapter", text)


if __name__ == "__main__":
    unittest.main()


class FunctionSchemaTest(unittest.TestCase):
    """tau2's tools reach the driver as plain JSON Schema, whichever way tau2 exposes them."""

    SCHEMA = {"type": "function", "function": {"name": "book", "description": "Book a flight", "parameters": {
        "type": "object", "required": ["passengers"],
        "properties": {"passengers": {"type": "array", "items": {"$ref": "#/$defs/Passenger"}}},
        "$defs": {"Passenger": {"type": "object", "properties": {"name": {"type": "string"}}}}}}}

    def test_property_and_method_forms_give_the_same_function(self):
        from prism_bench.driver import function_schema

        class AsProperty:
            openai_schema = FunctionSchemaTest.SCHEMA

        class AsMethod:
            def openai_schema(self):
                return FunctionSchemaTest.SCHEMA

        self.assertEqual(function_schema(AsProperty()), function_schema(AsMethod()))

    def test_refs_are_inlined_so_each_property_stands_alone(self):
        from prism_bench.driver import function_schema

        class Tool:
            openai_schema = FunctionSchemaTest.SCHEMA

        params = function_schema(Tool())["parameters"]
        self.assertNotIn("$defs", params)
        self.assertEqual(params["properties"]["passengers"]["items"], {"type": "object", "properties": {"name": {"type": "string"}}})
        self.assertNotIn("$ref", json.dumps(params))

    def test_a_self_referential_model_is_cut_off_not_expanded_forever(self):
        from prism_bench.driver import inline_refs

        node = {"type": "object", "properties": {"child": {"$ref": "#/$defs/Node"}},
                "$defs": {"Node": {"type": "object", "properties": {"child": {"$ref": "#/$defs/Node"}}}}}
        self.assertIn("truncated", json.dumps(inline_refs(node)))
