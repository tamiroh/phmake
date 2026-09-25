.LIBPATTERNS = lib%.so lib%.a
vpath %.a archive
vpath %.so shared
vpath %.txt wrong
vpath %.txt
vpath %.txt source
vpath % alternate fallback
all: item.txt -lfirst -lsecond -lthird
	@echo $^
