<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile;

use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Execution\Recipe\Command;
use Tamiroh\Phmake\Makefile\IO\SourceFiles;
use Tamiroh\Phmake\Makefile\Rule\BuildRule;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Makefile\Rule\Prerequisites;
use Tamiroh\Phmake\Makefile\Rule\Recipe;

use function array_filter;
use function implode;
use function in_array;

use const ARRAY_FILTER_USE_BOTH;

final class Builtins
{
    /** Variables retained when -R disables built-in tool definitions. */
    public const array INTERNAL_VARIABLES = [
        'SHELL',
        'MAKE',
        'MAKE_COMMAND',
        'MAKEOVERRIDES',
        'MAKECMDGOALS',
        'SUFFIXES',
        '.FEATURES',
        '.INCLUDE_DIRS',
        '.VARIABLES',
        '.RECIPEPREFIX',
        '.LOADED',
        '.SHELLFLAGS',
    ];

    /** Makefiles tried in order when none is named with -f. */
    public const array MAKEFILES = ['GNUmakefile', 'makefile', 'Makefile'];

    /**
     * Define the variables describing this invocation of make.
     *
     * @param array<string, Variable> $defaults
     * @param list<string> $goals
     *
     * @return list<Variable>
     */
    public static function invocationVariables(
        array $defaults,
        array $goals,
        int $level,
        string $directory,
        int $restarts,
    ): array {
        return [
            new Variable('.DEFAULT_GOAL', '', false),
            ...(
                isset($defaults['GNUMAKEFLAGS'])
                    ? [new Variable('GNUMAKEFLAGS', '', true, $defaults['GNUMAKEFLAGS']->origin)]
                    : []
            ),
            new Variable('MAKELEVEL', (string) $level, false, 'environment'),
            new Variable('CURDIR', $directory, false),
            ...($goals === [] ? [] : [new Variable('MAKECMDGOALS', implode(' ', $goals), false, 'default')]),
            ...($restarts === 0 ? [] : [new Variable('MAKE_RESTARTS', (string) $restarts, false, export: false)]),
        ];
    }

    /**
     * Return the first default makefile that exists, or every candidate so each is reported as missing.
     *
     * @return list<string>
     */
    public static function makefiles(SourceFiles $files): array
    {
        foreach (self::MAKEFILES as $path) {
            if ($files->isFile($path)) {
                return [$path];
            }
        }
        return self::MAKEFILES;
    }

    /**
     * @return list<Variable>
     */
    public static function posixVariables(): array
    {
        return [
            new Variable('.SHELLFLAGS', '-ec', false, 'default'),
            new Variable('CC', 'c99', false, 'default'),
            new Variable('CFLAGS', '-O1', false, 'default'),
            new Variable('FC', 'fort77', false, 'default'),
            new Variable('FFLAGS', '-O1', false, 'default'),
            new Variable('SCCSGETFLAGS', '-s', false, 'default'),
            new Variable('ARFLAGS', '-rv', false, 'default'),
        ];
    }

    /**
     * @return list<PatternRule>
     */
    public static function rules(): array
    {
        return [
            new PatternRule(
                ['(%)'],
                new BuildRule(new Prerequisites(['%']), new Recipe([new Command('$(AR) $(ARFLAGS) $@ $<')])),
            ),
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
            new Variable('.VARIABLES', '', false, 'default'),
            new Variable('.RECIPEPREFIX', '', false, 'default'),
            new Variable('.LOADED', '', false, 'default'),
            new Variable('GNUMAKEFLAGS', '', true, 'default'),
            new Variable('SUFFIXES', '.o .c .f', false, 'default'),
            new Variable('.LIBPATTERNS', 'lib%.so lib%.a', origin: 'default'),
            new Variable('.FEATURES', 'jobserver jobserver-fifo output-sync check-symlink archives', false, 'default'),
            new Variable('CC', 'cc', origin: 'default'),
            new Variable('FC', 'f77', origin: 'default'),
            new Variable('LEX', 'lex', origin: 'default'),
            new Variable('YACC', 'yacc', origin: 'default'),
            new Variable('LINK.o', '$(CC) $(LDFLAGS) $(TARGET_ARCH)', origin: 'default'),
            new Variable('LINK.c', '$(CC) $(CFLAGS) $(CPPFLAGS) $(LDFLAGS) $(TARGET_ARCH)', origin: 'default'),
            new Variable('LINK.f', '$(FC) $(FFLAGS) $(LDFLAGS) $(TARGET_ARCH)', origin: 'default'),
            new Variable('COMPILE.f', '$(FC) $(FFLAGS) $(TARGET_ARCH) -c', origin: 'default'),
            new Variable('AR', 'ar', origin: 'default'),
            new Variable('ARFLAGS', '-rv', origin: 'default'),
            new Variable('RM', 'rm -f', origin: 'default'),
            new Variable('SHELL', '/bin/sh', origin: 'default'),
            new Variable('.SHELLFLAGS', '-c', false, 'default'),
            new Variable('COMPILE.c', '$(CC) $(CFLAGS) $(CPPFLAGS) $(TARGET_ARCH) -c', origin: 'default'),
            new Variable('OUTPUT_OPTION', '-o $@', origin: 'default'),
        ];
    }

    /**
     * Remove the default variables disabled by -R.
     *
     * @param array<string, Variable> $variables
     *
     * @return array<string, Variable>
     */
    public static function withoutVariables(array $variables): array
    {
        return array_filter(
            $variables,
            static fn(Variable $variable, string $name): bool => (
                $variable->origin !== 'default'
                || in_array($name, self::INTERNAL_VARIABLES, true)
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
