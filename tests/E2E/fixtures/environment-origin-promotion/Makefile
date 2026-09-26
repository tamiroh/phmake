$(info before=$(origin SAMPLE):$(SAMPLE))
SAMPLE ?= conditional
$(info conditional=$(origin SAMPLE):$(SAMPLE))
all: SAMPLE = target
all:; @echo target=$(origin SAMPLE):$(SAMPLE)
