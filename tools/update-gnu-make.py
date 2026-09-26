#!/usr/bin/env python3
"""Update the GNU make reference pins from the official stable release index."""

import hashlib
from pathlib import Path
import re
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parent.parent
RELEASES = "https://ftp.gnu.org/gnu/make/"
VERSION_FILES = (
    "GNU_MAKE_VERSION",
    "tools/install-gnu-make.sh", "e2e/README.md", "e2e/make/README.md",
    "e2e/make/Dockerfile", "e2e/make/run-tests.sh",
    "e2e/make/verify.sh", "e2e/make/verify-runner.pl",
)
PIN_FILES = ("tools/install-gnu-make.sh", "e2e/make/Dockerfile")


def version_key(version):
    # Treat 4.4 and 4.4.0 as the same release; exclude prereleases in the index.
    return tuple(int(part) for part in version.split(".")) + (0,) * (3 - len(version.split(".")))


def main():
    current = (ROOT / "GNU_MAKE_VERSION").read_text().strip()
    if not re.fullmatch(r"\d+\.\d+(?:\.\d+)?", current):
        raise ValueError("Invalid GNU_MAKE_VERSION")
    with urlopen(RELEASES, timeout=60) as response:
        versions = re.findall(r'href="make-(\d+\.\d+(?:\.\d+)?)\.tar\.gz"', response.read().decode())
    if not versions:
        raise RuntimeError("No stable GNU make releases found in the official index")
    latest = max(versions, key=version_key)
    if version_key(latest) <= version_key(current):
        print(f"GNU make {current} is already current")
        return

    digest = hashlib.sha256()
    with urlopen(f"{RELEASES}make-{latest}.tar.gz", timeout=60) as response:
        while chunk := response.read(1024 * 1024):
            digest.update(chunk)

    # Prepare and validate every replacement before writing any file.
    updates = {}
    for name in VERSION_FILES:
        text = (ROOT / name).read_text()
        if current not in text:
            raise RuntimeError(f"Missing current version in {name}; update the updater")
        updates[name] = text.replace(current, latest)
    for name in PIN_FILES:
        updates[name], count = re.subn(r"\b[0-9a-f]{64}(?=  (?:/tmp/)?make\.tar\.gz)", digest.hexdigest(), updates[name])
        if count != 1:
            raise RuntimeError(f"Expected exactly one archive checksum in {name}")
    for name, text in updates.items():
        (ROOT / name).write_text(text)
    print(f"Updated GNU make {current} to {latest}, SHA-256 {digest.hexdigest()}")


if __name__ == "__main__":
    main()
