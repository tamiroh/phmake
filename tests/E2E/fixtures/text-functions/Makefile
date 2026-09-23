COMMA = ,
EMPTY =
LIST = b.c a.h b.c dir/note

all:
	@printf '<%s>\n' '$(subst a,b,a$(COMMA)a)' '$(subst ,!,text)' '$(subst a,b, a,a)'
	@printf '<%s>\n' '$(patsubst %.c,%.o,$(LIST))' '$(patsubst exact,new,exact exactish)'
	@printf '<%s>\n' '$(filter %.c,$(LIST))' '$(filter-out %.c,$(LIST))'
	@printf '<%s>\n' '$(sort $(LIST))' '$(strip  a   b  )' '$(findstring ir,dir/note)'
	@printf '<%s>\n' '$(words $(LIST))' '$(word 2,$(LIST))' '$(wordlist 2,3,$(LIST))' '$(firstword $(LIST))' '$(lastword $(LIST))'
	@printf '<%s>\n' '$(addprefix out/,$(addsuffix .o,a b))' '$(join a b c,1 2)' '$(join a,1 2)'
	@printf '<%s>\n' '$(dir a path/b path/)' '$(notdir a path/b path/)' '$(basename path.ext/file.c noext .hidden)' '$(suffix path.ext/file.c noext .hidden)'
	@printf '<%s>\n' '${subst a,b,${subst x,a,x}}' '$(subst a,b,(a,a))' '$(word 0002,a b c)' '$(wordlist 2,0,a b)'
	@printf '<%s>\n' '$(patsubst a,%, a   b )' '$(patsubst %,,a b)' '$(patsubst a,,a b a)'
