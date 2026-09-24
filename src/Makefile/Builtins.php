<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

final class Builtins
{
    /** @return list<PatternRule> */
    public static function rules(): array
    {
        return [new PatternRule(
            ['%.o'],
            new BuildRule(new Prerequisites(['%.c']), new Recipe([new Command('$(COMPILE.c) $(OUTPUT_OPTION) $<')])),
        )];
    }

    /** @return list<Variable> */
    public static function variables(): array
    {
        return [
            new Variable('CC', 'cc', origin: 'default'),
            new Variable('AR', 'ar', origin: 'default'),
            new Variable('SHELL', '/bin/sh', origin: 'default'),
            new Variable('.SHELLFLAGS', '-c', false, 'default'),
            new Variable('COMPILE.c', '$(CC) $(CFLAGS) $(CPPFLAGS) $(TARGET_ARCH) -c', origin: 'default'),
            new Variable('OUTPUT_OPTION', '-o $@', origin: 'default'),
        ];
    }
}
