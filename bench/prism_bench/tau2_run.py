"""`python -m prism_bench.tau2_run <tau2 run args>`: register the capped agents, then run tau2's own CLI."""

from __future__ import annotations

from .tau2_agent import register


def main() -> None:
    register()
    from tau2.cli import main as tau2_main

    tau2_main()


if __name__ == "__main__":
    main()
