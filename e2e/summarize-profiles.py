#!/usr/bin/env python3
"""Print the hottest functions in phmake rebuild profiles."""

import argparse
from collections import Counter
from pathlib import Path

# Matches the sampling period set in e2e/profile/prepend.php.
PERIOD = 0.001
TOP = 20


def summarize(project, profiles_path):
    summary = [f"### {project} phmake profile", ""]
    phases = sorted(path for path in Path(profiles_path).iterdir() if path.is_dir())
    if not phases:
        summary.extend(["No profiles were written. Check the container output.", ""])
    for phase in phases:
        processes = sorted(phase.glob("*.folded"))
        own, total, samples = Counter(), Counter(), 0
        for process in processes:
            for line in process.read_text(encoding="utf-8").splitlines():
                stack, _, count = line.rpartition(" ")
                frames = stack.split(";")
                samples += int(count)
                own[frames[-1]] += int(count)
                for frame in set(frames):
                    total[frame] += int(count)
        summary.extend([
            f"#### {phase.name}",
            "",
            f"{len(processes)} phmake processes, {samples * PERIOD:.2f} s sampled in total.",
            "",
        ])
        if not samples:
            continue
        summary.extend(table("Self time", own, samples))
        summary.extend(table("Inclusive time", total, samples))
    summary.extend([
        f"Wall-clock samples every {PERIOD * 1000:g} ms, summed over processes; recursive makes overlap in time.",
        "The clean build runs with GNU make and is not profiled.",
        "",
    ])
    print("\n".join(summary), flush=True)


def table(title, counts, samples):
    rows = [f"| Function | {title} | Share |", "| --- | --- | --- |"]
    for frame, count in counts.most_common(TOP):
        name = frame.replace("|", "\\|")
        rows.append(f"| `{name}` | {count * PERIOD:.3f} s | {count / samples:.1%} |")
    return [*rows, ""]


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("project", help="project name shown in the heading")
    parser.add_argument("profiles", help="directory with one subdirectory of .folded files per phase")
    arguments = parser.parse_args()
    summarize(arguments.project, arguments.profiles)
