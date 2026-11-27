"""prism_bench: run harness benchmarks (Terminal-Bench via Harbor, tau2-bench) against prism-harness drivers.

The adapter side of prism-bench-driver/1 (prism-parity specs/bench-driver.md).
Core modules use the standard library only; harbor_agent and tau2_agent import
their benchmark and are loaded only inside harness-bench.yml.
"""
