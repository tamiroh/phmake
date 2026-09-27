#!/usr/bin/env python3
"""Fail when phmake's no-op rebuild exceeds a time limit in the comparisons written by compare-builds.py."""

import argparse
import json
import os
from pathlib import Path


def check(limit, results_directory, projects):
    summary = [
        f"### No-op rebuild limit: {limit:g} s",
        "",
        "| Project | GNU make | phmake | Result |",
        "| --- | --- | --- | --- |",
    ]
    failed = False
    for project in projects:
        # download-artifact places each artifact in a directory named after it.
        path = Path(results_directory) / f"build-results-{project}" / "build-results.json"
        times = {}
        if path.is_file():
            for run in json.loads(path.read_text(encoding="utf-8"))["runs"]:
                times[run["implementation"]] = run["phases"].get("no-op-rebuild")
        gnu, phmake = times.get("gnu"), times.get("phmake")
        if phmake is None:
            result = "Missing"
        elif phmake > limit:
            result = "Too slow"
        else:
            result = "Passed"
        failed = failed or result != "Passed"
        summary.append(f"| {project} | {seconds(gnu)} | {seconds(phmake)} | {result} |")
    summary.extend([
        "",
        "Times come from the build comparison jobs. Missing means the job wrote no no-op rebuild time.",
        "GNU make is shown for reference; if it also exceeds the limit, the runner was probably slow.",
        "",
    ])
    print("\n".join(summary), flush=True)
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with Path(os.environ["GITHUB_STEP_SUMMARY"]).open("a", encoding="utf-8") as output:
            output.write("\n".join(summary) + "\n")
    return int(failed)


def seconds(value):
    return "—" if value is None else f"{value:.2f} s"


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("limit", type=float, help="maximum phmake no-op rebuild time in seconds")
    parser.add_argument("results", help="directory with one build-results-PROJECT artifact per project")
    parser.add_argument("projects", nargs="+", help="projects that must meet the limit")
    arguments = parser.parse_args()
    raise SystemExit(check(arguments.limit, arguments.results, arguments.projects))
