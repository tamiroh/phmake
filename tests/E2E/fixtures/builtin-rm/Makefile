.PHONY: all inspect remove
all: inspect remove

ifeq ($(SILENT_RM),yes)
$(eval override RM = @$(RM))
endif

inspect:
	@printf '%s|%s|%s\n' '$(RM)' '$(origin RM)' '$(flavor RM)'

remove:
	@printf 'sample\n' > artifact
	$(RM) artifact missing-artifact
	@test ! -e artifact
	@echo removed
