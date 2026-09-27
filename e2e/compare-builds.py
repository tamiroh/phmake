#!/usr/bin/env python3
"""Run the same build smoke test with GNU make and phmake in fresh containers."""

import argparse
import os
from pathlib import Path
import re
import subprocess
import sys
import time

PHASE = re.compile(r"@@phase (\S+) ([0-9.]+) 0$")


def run_container(project, implementation):
    """Stream the container output and collect the phases reported by e2e/phase.sh."""
    process = subprocess.Popen(
        [
            "docker", "run", "--rm", "--init", "--platform", "linux/amd64",
            "--network", "none", "-e", f"MAKE_IMPLEMENTATION={implementation}",
            f"phmake-{project}-build",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    phases = {}
    for raw in process.stdout:
        sys.stdout.buffer.write(raw)
        sys.stdout.flush()
        match = PHASE.match(raw.decode(errors="replace").rstrip("\n"))
        if match:
            phases[match[1]] = float(match[2])
    return process.wait(), phases


def compare(project):
    results = []
    for implementation in ("gnu", "phmake"):
        print(f"\n=== {project}: {implementation} ===", flush=True)
        started = time.perf_counter()
        try:
            status, phases = run_container(project, implementation)
        except OSError as error:
            print(f"Cannot start Docker: {error}", flush=True)
            status, phases = 127, {}
        results.append((implementation, status, time.perf_counter() - started, phases))

    summary = [
        f"### {project} build comparison",
        "",
        "| Implementation | Result | Elapsed time |",
        "| --- | --- | --- |",
    ]
    for implementation, status, elapsed, _ in results:
        name = "GNU make" if implementation == "gnu" else "phmake"
        result = "Passed" if status == 0 else f"Failed (exit {status})"
        summary.append(f"| {name} | {result} | {elapsed:.2f} s |")
    if all(status == 0 for _, status, _, _ in results) and results[0][2] > 0:
        summary.extend([
            "",
            f"phmake / GNU make elapsed-time ratio: **{results[1][2] / results[0][2]:.2f}×**",
        ])
    gnu_phases, phmake_phases = results[0][3], results[1][3]
    if gnu_phases or phmake_phases:
        summary.extend([
            "",
            "| Phase | GNU make | phmake | phmake / GNU make |",
            "| --- | --- | --- | --- |",
        ])
        for name in {**gnu_phases, **phmake_phases}:
            gnu, phmake = gnu_phases.get(name), phmake_phases.get(name)
            ratio = f"{phmake / gnu:.2f}×" if gnu and phmake is not None else "—"
            summary.append(f"| {name} | {seconds(gnu)} | {seconds(phmake)} | {ratio} |")
    summary.extend([
        "",
        "One run each, GNU make first, on the same runner and image with fresh containers.",
        "Elapsed time includes container startup, build, and verification; image preparation is excluded.",
        "Host caches and runner load may affect the comparison.",
        "",
    ])
    print("\n".join(summary), flush=True)
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with Path(os.environ["GITHUB_STEP_SUMMARY"]).open("a", encoding="utf-8") as output:
            output.write("\n".join(summary) + "\n")
    return int(any(status != 0 for _, status, _, _ in results))


def seconds(value):
    return "—" if value is None else f"{value:.2f} s"


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("project", choices=("php", "lua", "make", "git", "linux", "ffmpeg", "gcc"))
    raise SystemExit(compare(parser.parse_args().project))
