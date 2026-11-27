"""Static guards on the paid workflow and the free probe. Text checks; no YAML library needed.

If one of these fails, the workflow lost a guard the owner's approval was given on.
"""

import re
import unittest
from pathlib import Path

WORKFLOWS = Path(__file__).resolve().parents[2] / ".github" / "workflows"
PAID = (WORKFLOWS / "harness-bench.yml").read_text(encoding="utf-8")
PROBE = (WORKFLOWS / "bench-probe.yml").read_text(encoding="utf-8")


def jobs(text):
    body = text.split("\njobs:\n", 1)[1]
    parts = re.split(r"\n  (?=[a-z][a-z0-9_-]*:\n)", "\n" + body)
    return {p.split(":", 1)[0].strip(): p for p in parts if p.strip()}


def steps(job_text):
    return re.split(r"\n      - ", job_text)[1:]


def plain_scalar_colons(text):
    """Plain (unquoted, non-block) scalars that contain ': ' -- YAML reads those as a mapping and
    GitHub rejects the whole file. bench-probe.yml shipped one (`--only-binary=:all: harbor`)."""
    bad, block = [], None
    for number, line in enumerate(text.splitlines(), 1):
        indent = len(line) - len(line.lstrip())
        if block is not None:
            if line.strip() == "" or indent > block:
                continue
            block = None
        match = re.match(r"^(\s*)(?:- )?[\w.-]+:\s+(.*)$", line)
        if not match:
            continue
        value = match.group(2).split(" #", 1)[0].rstrip()
        if value[:1] in ("|", ">"):
            block = len(match.group(1))
            continue
        if value[:1] in ("'", '"', "{", "["):
            continue
        if ": " in value or value.endswith(":"):
            bad.append(f"{number}: {line.strip()}")
    return bad


class YamlShapeTest(unittest.TestCase):
    def test_no_plain_scalar_contains_a_mapping_colon(self):
        for path in sorted(WORKFLOWS.glob("*.yml")):
            self.assertEqual(plain_scalar_colons(path.read_text(encoding="utf-8")), [], path.name)

    def test_the_guard_catches_the_shape_that_broke_bench_probe(self):
        broken = "jobs:\n  a:\n    steps:\n      - run: pip install --only-binary=:all: x\n"
        fixed = "jobs:\n  a:\n    steps:\n      - run: |\n          pip install --only-binary=:all: x\n"
        self.assertTrue(plain_scalar_colons(broken))
        self.assertFalse(plain_scalar_colons(fixed))


class PaidWorkflowTest(unittest.TestCase):
    def test_it_is_dispatch_only(self):
        on = PAID.split("\non:\n", 1)[1].split("\npermissions:", 1)[0]
        self.assertIn("workflow_dispatch:", on)
        for trigger in ("push", "pull_request", "schedule", "workflow_run", "issue", "repository_dispatch"):
            self.assertNotRegex(on, rf"(?m)^  {trigger}")

    def test_top_level_permissions_are_empty_and_plan_is_main_only(self):
        self.assertIn("\npermissions: {}\n", PAID)
        self.assertIn("if: github.ref == 'refs/heads/main'", jobs(PAID)["plan"])

    def test_every_action_is_pinned_to_a_full_commit_sha(self):
        for text in (PAID, PROBE):
            for ref in re.findall(r"uses:\s*([^\s#]+)", text):
                self.assertRegex(ref, r"^[\w.-]+/[\w.-]+@[0-9a-f]{40}$", ref)

    def test_secrets_appear_only_on_the_run_steps(self):
        for name, job in jobs(PAID).items():
            for step in steps(job):
                if "secrets." in step:
                    self.assertIn(name, ("tb", "tau2"), f"secret referenced in job {name}")
                    self.assertTrue(step.startswith("name: Run the"), "a secret outside the step that runs the benchmark")
        self.assertNotIn("secrets.", jobs(PAID)["publish"])
        self.assertNotIn("secrets.", PROBE)

    def test_the_run_jobs_are_capped_in_parallelism_and_time(self):
        for name in ("tb", "tau2"):
            self.assertRegex(jobs(PAID)[name], r"max-parallel: [1-4]\n")
            self.assertRegex(jobs(PAID)[name], r"timeout-minutes: \d+\n")

    def test_a_scrub_precedes_every_upload_and_gates_it(self):
        for name in ("tb", "tau2"):
            job = jobs(PAID)[name]
            self.assertLess(job.index("prism_bench.cli scrub"), job.index("upload-artifact"))
            self.assertIn("steps.scrub.outcome == 'success'", job)
        self.assertIn("prism_bench.cli scrub artifacts", jobs(PAID)["publish"])

    def test_installs_in_the_paid_run_are_hash_pinned(self):
        for line in re.findall(r"pip install[^\n]*", PAID):
            self.assertTrue("--require-hashes" in line or ("--no-deps" in line and "-e tau2-src" in line), line)
        # PyPI's "tau2" is an unrelated project: the bare name must never be installed.
        for line in re.findall(r"pip install[^\n]*", PAID + PROBE):
            self.assertNotRegex(line, r"(?<![/\w.-])tau2(?![\w./-])", line)

    def test_inputs_reach_shell_only_through_env(self):
        for step in [s for j in jobs(PAID).values() for s in steps(j)]:
            run = step.split("run:", 1)[1] if "run:" in step else ""
            self.assertNotIn("${{ inputs.", run, "an input interpolated into a shell script is an injection")

    def test_the_publish_job_signs_and_holds_no_key(self):
        publish = jobs(PAID)["publish"]
        self.assertIn("attest-build-provenance", publish)
        self.assertIn("id-token: write", publish)
        self.assertNotIn("ANTHROPIC_API_KEY", publish)
        self.assertNotIn("OPENAI_API_KEY", publish)


if __name__ == "__main__":
    unittest.main()
