VALUE = child-file
FIXED = child-file
child:
	@printf '%s\n' 'child=$(MAKELEVEL)|$(VALUE)|$(origin VALUE)|$(FIXED)|$(flavor FIXED)|$(MAKECMDGOALS)'
