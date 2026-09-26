NAMES := .VARIABLES .RECIPEPREFIX .LOADED GNUMAKEFLAGS MFLAGS MAKE_COMMAND .DEFAULT_GOAL
$(info origins=$(foreach name,$(NAMES),$(name):$(origin $(name)):$(flavor $(name))))
$(info names=$(sort $(filter .VARIABLES .RECIPEPREFIX .LOADED NAMES,$(.VARIABLES))))
.VARIABLES := replaced
$(info changed=$(.VARIABLES))
undefine .VARIABLES
undefine .RECIPEPREFIX
$(info removed=$(.VARIABLES)$(.RECIPEPREFIX))
all:;
