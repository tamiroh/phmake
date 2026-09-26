all:
	@./plain.sh 'two words' 'semi;colon'
	@printf '<%s>\n' 'a=b' ''
missing:
	@./missing-tool argument
permission:
	@./not-executable
capture:
	@echo status=$(shell ./missing-tool)$(.SHELLSTATUS)
