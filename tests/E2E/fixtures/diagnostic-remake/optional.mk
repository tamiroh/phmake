-include missing.mk
all:; @echo survived
missing.mk: prerequisite
prerequisite:; @echo attempt; false
