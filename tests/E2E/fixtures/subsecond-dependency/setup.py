import os
from pathlib import Path

# Fixed timestamps avoid sleeps and keep both files within the same second.
for name, nanoseconds in (
    ("output", 1700000000000000000),
    ("input", 1700000000900000000),
):
    path = Path(name)
    path.write_text(name + "\n")
    os.utime(path, ns=(nanoseconds, nanoseconds))
    assert path.stat().st_mtime_ns == nanoseconds, "Subsecond timestamps required"
