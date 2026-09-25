<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\Command;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Rule\Recipe;

final class Builtins
{
    /**
     * @return list<PatternRule>
     */
    public static function rules(): array
    {
        return [
            new PatternRule(
                ['%'],
                new BuildRule(
                    new Prerequisites(['%.o']),
                    new Recipe([new Command('$(LINK.o) $^ $(LOADLIBES) $(LDLIBS) -o $@')]),
                ),
            ),
            new PatternRule(
                ['%'],
                new BuildRule(
                    new Prerequisites(['%.c']),
                    new Recipe([new Command('$(LINK.c) $^ $(LOADLIBES) $(LDLIBS) -o $@')]),
                ),
            ),
            new PatternRule(
                ['%'],
                new BuildRule(
                    new Prerequisites(['%.f']),
                    new Recipe([new Command('$(LINK.f) $^ $(LOADLIBES) $(LDLIBS) -o $@')]),
                ),
            ),
            new PatternRule(
                ['%.o'],
                new BuildRule(
                    new Prerequisites(['%.c']),
                    new Recipe([new Command('$(COMPILE.c) $(OUTPUT_OPTION) $<')]),
                ),
            ),
            new PatternRule(
                ['%.o'],
                new BuildRule(
                    new Prerequisites(['%.f']),
                    new Recipe([new Command('$(COMPILE.f) $(OUTPUT_OPTION) $<')]),
                ),
            ),
            new PatternRule(['%.c'], new BuildRule()),
            new PatternRule(['%.f'], new BuildRule()),
        ];
    }

    /**
     * @return list<Variable>
     */
    public static function variables(): array
    {
        return [
            new Variable('.LIBPATTERNS', 'lib%.so lib%.a', origin: 'default'),
            new Variable('CC', 'cc', origin: 'default'),
            new Variable('FC', 'f77', origin: 'default'),
            new Variable('LINK.o', '$(CC) $(LDFLAGS) $(TARGET_ARCH)', origin: 'default'),
            new Variable('LINK.c', '$(CC) $(CFLAGS) $(CPPFLAGS) $(LDFLAGS) $(TARGET_ARCH)', origin: 'default'),
            new Variable('LINK.f', '$(FC) $(FFLAGS) $(LDFLAGS) $(TARGET_ARCH)', origin: 'default'),
            new Variable('COMPILE.f', '$(FC) $(FFLAGS) $(TARGET_ARCH) -c', origin: 'default'),
            new Variable('AR', 'ar', origin: 'default'),
            new Variable('SHELL', '/bin/sh', origin: 'default'),
            new Variable('.SHELLFLAGS', '-c', false, 'default'),
            new Variable('COMPILE.c', '$(CC) $(CFLAGS) $(CPPFLAGS) $(TARGET_ARCH) -c', origin: 'default'),
            new Variable('OUTPUT_OPTION', '-o $@', origin: 'default'),
        ];
    }
}
