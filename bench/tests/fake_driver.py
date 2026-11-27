"""A stand-in driver for adapter tests. Never calls a provider.

    fake_driver.py replay FIXTURE   speak the fixture's driver lines; exit 3 if an
                                    adapter line differs from the fixture's
    fake_driver.py env              report the environment variable NAMES it got
    fake_driver.py junk             write a non-protocol line to stdout
    fake_driver.py huge             write a line over 8 MiB
    fake_driver.py spender          a tool call after a usage that spends $1
"""
import json
import os
import sys


def out(msg):
    sys.stdout.write(json.dumps(msg) + "\n")
    sys.stdout.flush()


def read():
    line = sys.stdin.readline()
    return json.loads(line) if line else None


mode = sys.argv[1]
if mode == "replay":
    lines = [json.loads(l) for l in open(sys.argv[2], encoding="utf-8") if l.strip()]
    for entry in lines:
        if entry["dir"] == "driver":
            out(entry["msg"])
        else:
            got = read()
            want = entry["msg"]
            if got != want:
                sys.stderr.write("adapter line differs:\n got  %s\n want %s\n" % (json.dumps(got, sort_keys=True), json.dumps(want, sort_keys=True)))
                sys.exit(3)
elif mode == "env":
    out({"v": 1, "type": "hello", "protocol": "prism-bench-driver/1", "driver": {"name": "fake", "harness_version": "0", "runtime": "py"}})
    read()
    out({"v": 1, "type": "end", "status": "refused", "code": ",".join(sorted(os.environ)), "final_text": "", "steps": 0, "charged_usd": 0})
elif mode == "junk":
    sys.stdout.write("Warning: something printed to stdout\n")
    sys.stdout.flush()
elif mode == "huge":
    sys.stdout.write("x" * (8 * 1024 * 1024 + 10) + "\n")
    sys.stdout.flush()
elif mode == "spender":
    out({"v": 1, "type": "hello", "protocol": "prism-bench-driver/1", "driver": {"name": "fake", "harness_version": "0", "runtime": "py"}})
    read()
    out({"v": 1, "type": "tool_call", "call_id": "t1", "name": "bash", "arguments": {"command": "true"}})
    read()
    out({"v": 1, "type": "usage", "call_id": "m1", "outcome": "completed", "usage_known": True, "input_tokens": 10,
         "cache_write_tokens": 0, "cache_read_tokens": 0, "output_tokens": 1, "reasoning_tokens": None, "charged_usd": 1.0})
    out({"v": 1, "type": "tool_call", "call_id": "t2", "name": "bash", "arguments": {"command": "true"}})
    reply = read()
    status = "aborted" if reply and reply.get("type") == "abort" else "completed"
    out({"v": 1, "type": "end", "status": status, "code": None, "final_text": "", "steps": 1, "charged_usd": 1.0})
