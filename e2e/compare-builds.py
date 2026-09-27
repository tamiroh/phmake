#!/usr/bin/env python3
"""Run the same build smoke test with GNU make and phmake in fresh containers.

The results are written as JSON for summarize-builds.py.
"""

import argparse
import json
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


def compare(project, results_path):
    runs = []
    for implementation in ("gnu", "phmake"):
        print(f"\n=== {project}: {implementation} ===", flush=True)
        started = time.perf_counter()
        try:
            status, phases = run_container(project, implementation)
        except OSError as error:
            print(f"Cannot start Docker: {error}", flush=True)
            status, phases = 127, {}
        runs.append({
            "implementation": implementation,
            "status": status,
            "elapsed": time.perf_counter() - started,
            "phases": phases,
        })
    Path(results_path).write_text(json.dumps({"project": project, "runs": runs}, indent=2) + "\n", encoding="utf-8")
    return int(any(run["status"] != 0 for run in runs))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("project", choices=("php", "lua", "make", "git", "linux", "ffmpeg", "gcc"))
    parser.add_argument("results", help="JSON file to write for summarize-builds.py")
    arguments = parser.parse_args()
    raise SystemExit(compare(arguments.project, arguments.results))
