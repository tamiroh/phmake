$(file >generated.txt,first)
$(file >>generated.txt,second)
$(file >empty.txt)
$(file >newline.txt,)
TEXT := $(file <generated.txt)
export TEXT
all:
	@printf '%s\n' "$$TEXT"
	@printf '<%s>\n' '$(file <missing)' '$(wildcard *.txt)' '$(notdir $(abspath ./none/../missing))' '$(notdir $(realpath linked.txt missing))'
	@test ! -s empty.txt
	@test "$$(wc -c < newline.txt | tr -d ' ')" = 1
update:
	@echo $(file >later.txt,now)$(wildcard later.txt)
