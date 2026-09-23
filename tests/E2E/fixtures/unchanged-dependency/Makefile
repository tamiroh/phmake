all: output

output: version
	@echo rebuilding output
	@touch output

version: stamp

.PHONY: force
forced: force
	@echo rebuilding forced

missing-output: missing
	@echo rebuilding missing-output

missing:
