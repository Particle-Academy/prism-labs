"""Fail the job if anything key-shaped is in the artifacts.

Reports the FILE and the PATTERN NAME, never the match: a scrub that prints
what it found has published it.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

PATTERNS = {
    "anthropic-key": re.compile(rb"sk-ant-[A-Za-z0-9_\-]{16,}"),
    "openai-key": re.compile(rb"sk-(?:proj-|svcacct-|admin-)?[A-Za-z0-9_\-]{20,}"),
    "github-token": re.compile(rb"(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})"),
    "aws-access-key": re.compile(rb"(?:AKIA|ASIA)[0-9A-Z]{16}"),
    "private-key": re.compile(rb"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    "slack-token": re.compile(rb"xox[abposr]-[A-Za-z0-9-]{10,}"),
    "bearer-header": re.compile(rb"(?i)(?:authorization|x-api-key)\s*[:=]\s*[\"']?(?:bearer\s+)?[A-Za-z0-9_\-\.]{24,}"),
}


def scan(root: Path) -> list[tuple[str, str]]:
    hits = []
    root = Path(root)
    files = [root] if root.is_file() else sorted(root.rglob("*"))
    for path in files:
        if not path.is_file() or path.is_symlink():
            continue
        data = path.read_bytes()
        for name, pattern in PATTERNS.items():
            if pattern.search(data):
                hits.append((path.name if path == root else str(path.relative_to(root)), name))
    return hits


def main(argv: list[str]) -> int:
    failed = False
    for root in argv:
        for rel, name in scan(Path(root)):
            print(f"::error::key-shaped content ({name}) in {root}/{rel}")
            failed = True
    if failed:
        print("::error::Refusing to publish artifacts that contain key-shaped strings.")
        return 1
    print(f"scrub: no key-shaped content in {', '.join(argv)}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
