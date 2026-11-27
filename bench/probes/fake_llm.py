"""A local stand-in for the Anthropic and OpenAI APIs, for the FREE smoke job. Never forwards anything.

Answers any path ending in /messages (Anthropic Messages) or /chat/completions
(OpenAI) on 127.0.0.1 only. The assistant reply is a Terminus-2-parseable "task
complete" JSON, which the prism arm reads as plain final text. The simulated
user says one sentence and then ###STOP###, so a tau2 simulation reaches the
agent and ends.

    python bench/probes/fake_llm.py PORT
"""

from __future__ import annotations

import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

DONE = json.dumps({"analysis": "smoke test", "plan": "stop", "commands": [], "task_complete": True})


class Handler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:  # noqa: N802
        length = min(int(self.headers.get("content-length", "0")), 32 * 1024 * 1024)
        body = json.loads(self.rfile.read(length) or b"{}")
        if self.path.endswith("/messages"):
            reply = {"id": "msg_smoke", "type": "message", "role": "assistant", "model": body.get("model", "smoke"),
                     "content": [{"type": "text", "text": DONE}], "stop_reason": "end_turn", "stop_sequence": None,
                     "usage": {"input_tokens": 12, "output_tokens": 8, "cache_creation_input_tokens": 0, "cache_read_input_tokens": 0}}
        elif self.path.endswith("/chat/completions"):
            turns = sum(1 for m in body.get("messages", []) if m.get("role") in ("user", "assistant"))
            text = "Hi, I would like to check my reservation." if turns <= 2 else "###STOP###"
            reply = {"id": "chatcmpl-smoke", "object": "chat.completion", "created": 0, "model": body.get("model", "smoke"),
                     "choices": [{"index": 0, "message": {"role": "assistant", "content": text}, "finish_reason": "stop"}],
                     "usage": {"prompt_tokens": 12, "completion_tokens": 8, "total_tokens": 20}}
        else:
            self.send_error(404)
            return
        data = json.dumps(reply).encode()
        self.send_response(200)
        self.send_header("content-type", "application/json")
        self.send_header("content-length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args) -> None:
        pass


if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
