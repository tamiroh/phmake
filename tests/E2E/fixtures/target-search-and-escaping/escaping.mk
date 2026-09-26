self = $1
VALUE := $(call self,\#literal\#)#comment
all: foo\:bar foo\\\:bar
	@echo '$(VALUE)'
foo\:bar foo\\\:bar:; @echo '$@'
