#!/usr/bin/env python3
"""Run the same build smoke test with GNU make and phmake in fresh containers."""

import argparse
import os
from pathlib import Path
import subprocess
import time


def compare(project):
    results = []
    for implementation in ("gnu", "phmake"):
        print(f"\n=== {project}: {implementation} ===", flush=True)
        started = time.perf_counter()
        try:
            status = subprocess.run(
                [
                    "docker", "run", "--rm", "--init", "--platform", "linux/amd64",
                    "--network", "none", "-e", f"MAKE_IMPLEMENTATION={implementation}",
                    f"phmake-{project}-build",
                ],
                check=False,
            ).returncode
        except OSError as error:
            print(f"Cannot start Docker: {error}", flush=True)
            status = 127
        results.append((implementation, status, time.perf_counter() - started))

    summary = [
        f"### {project} build comparison",
        "",
        "| Implementation | Result | Elapsed time |",
        "| --- | --- | --- |",
    ]
    for implementation, status, elapsed in results:
        name = "GNU make" if implementation == "gnu" else "phmake"
        result = "Passed" if status == 0 else f"Failed (exit {status})"
        summary.append(f"| {name} | {result} | {elapsed:.2f} s |")
    if all(status == 0 for _, status, _ in results) and results[0][2] > 0:
        summary.extend([
            "",
            f"phmake / GNU make elapsed-time ratio: **{results[1][2] / results[0][2]:.2f}×**",
        ])
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
    return int(any(status != 0 for _, status, _ in results))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("project", choices=("php", "lua", "make", "git", "linux", "ffmpeg"))
    raise SystemExit(compare(parser.parse_args().project))
