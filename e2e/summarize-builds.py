#!/usr/bin/env python3
"""Print the comparison written by compare-builds.py and add it to the GitHub step summary."""

import argparse
import json
import os
from pathlib import Path


def summarize(results_path):
    results = json.loads(Path(results_path).read_text(encoding="utf-8"))
    runs = results["runs"]
    summary = [
        f"### {results['project']} build comparison",
        "",
        "| Implementation | Result | Elapsed time |",
        "| --- | --- | --- |",
    ]
    for run in runs:
        name = "GNU make" if run["implementation"] == "gnu" else "phmake"
        result = "Passed" if run["status"] == 0 else f"Failed (exit {run['status']})"
        summary.append(f"| {name} | {result} | {run['elapsed']:.2f} s |")
    if all(run["status"] == 0 for run in runs) and runs[0]["elapsed"] > 0:
        summary.extend([
            "",
            f"phmake / GNU make elapsed-time ratio: **{runs[1]['elapsed'] / runs[0]['elapsed']:.2f}×**",
        ])
    gnu_phases, phmake_phases = runs[0]["phases"], runs[1]["phases"]
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


def seconds(value):
    return "—" if value is None else f"{value:.2f} s"


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("results", help="JSON file written by compare-builds.py")
    summarize(parser.parse_args().results)
