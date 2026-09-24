# Makefile sessions

Each fixture contains a Makefile and a `session.txt` recording shell commands,
combined output, and nonzero exit statuses. The same session runs against phmake
and GNU make in separate temporary directories.

Use `GNU_MAKE=/path/to/make` to select the reference executable. A fixture may
include `gnu-version.txt` with its minimum reference version when it uses newer
GNU make behavior. An older reference skips only its comparison; the phmake test
always runs. Use GNU make 4.4 or later to run all comparisons.

Keep regressions observable through commands, generated files, and output rather
than asserting the internal parser or execution model.
