x = outer
name = x
pair = $1:$2
relay = $(foreach x,item,$(call pair,inner))
map = $(foreach x,$2,$(call $1,$x))
wrap = [$1]
all:
	@printf '<%s>\n' '$(foreach $(name),a b,[$x])' '$(foreach x,a b,$x:$(foreach x,1 2,$x):$x)|$x'
	@printf '<%s>\n' '$(foreach x,,unreachable)' '$(foreach x,a b c,)' '$(foreach x,$$(literal),$x)'
	@printf '<%s>\n' '$(call foreach,x,a b,$x)' '$(call foreach,x,a b,$$x)' '$(call relay,one,two)'
	@printf '<%s>\n' '$(call map,wrap,a b)' '$(foreach ,a b,constant)'
	@printf '<%s>\n' '$(foreach x,a b,$(info visit $x)$x)'
