all.foo.bar: %.foo.bar: %.one
all.foo.bar: %.bar: %.two
all.foo.bar: ; @echo $* $^
.DEFAULT: ; @:
