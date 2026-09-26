	VALUE := first \
	 second
	ifeq ($(VALUE),first second)
	RESULT := selected
	endif
	define BLOCK
one
two
endef
all:
	@echo '$(VALUE) $(RESULT)'
	$(info $(BLOCK))
NEXT := value
	SUFFIX := tail
more:
	@echo '$(NEXT) $(SUFFIX)'
