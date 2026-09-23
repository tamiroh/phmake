<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final class Builtins
{
    /** @return list<Target> */
    public static function rules(): array
    {
        return [new Target('%.o', ['%.c'], [new Command('$(COMPILE.c) $(OUTPUT_OPTION) $<')], false)];
    }

    /** @return list<Variable> */
    public static function variables(): array
    {
        return [
            new Variable('CC', 'cc'),
            new Variable('AR', 'ar'),
            new Variable('SHELL', '/bin/sh'),
            new Variable('COMPILE.c', '$(CC) $(CFLAGS) $(CPPFLAGS) $(TARGET_ARCH) -c'),
            new Variable('OUTPUT_OPTION', '-o $@'),
        ];
    }
}
