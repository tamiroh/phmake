.PHONY: all left right common
all: left right
	@cat common.log; cat left.value right.value
left: VALUE = left scope
right: VALUE = right scope
left right: common
	@sh rendezvous.sh $@ '$(VALUE)'
common:
	@echo shared >> common.log
