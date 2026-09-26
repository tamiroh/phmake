$(info before=$(origin SAMPLE):$(SAMPLE))
SAMPLE = file
$(info assigned=$(origin SAMPLE):$(SAMPLE))
all: SAMPLE = target
all:; @echo target=$(origin SAMPLE):$(SAMPLE)
