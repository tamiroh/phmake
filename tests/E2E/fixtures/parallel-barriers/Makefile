.PHONY: all first second last serial one two
all: first .WAIT second | .WAIT last
	@echo $^
first:
	@sleep 0.05; echo first > first
second:
	@test -f first && echo second > second
last:
	@test -f second && echo last > last
.NOTPARALLEL: serial
serial: one two
	@echo serial
one:
	@sleep 0.05; touch one
two:
	@test -f one
