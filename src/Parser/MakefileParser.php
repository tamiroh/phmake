<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use LogicException;
use Tamiroh\Phmake\Makefile\Assignment;
use Tamiroh\Phmake\Makefile\EvaluationContext;
use Tamiroh\Phmake\Makefile\Exports;
use Tamiroh\Phmake\Makefile\Filesystem;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Output;
use Tamiroh\Phmake\Makefile\PatternRule;
use Tamiroh\Phmake\Makefile\Shell;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function intdiv;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final readonly class MakefileParser
{
    /**
     * @param list<Variable> $defaults
     * @param array<string, Variable> $overrides
     * @param list<PatternRule> $builtinRules
     * @param array<int, string> $sources
     */
    public function __construct(
        private string $source,
        private ?SourceFiles $files = null,
        private array $defaults = [],
        private array $overrides = [],
        private array $builtinRules = [],
        private ?Output $output = null,
        private array $sources = [],
        private ?Configuration $configuration = null,
        private ?Shell $shell = null,
        private ?Filesystem $filesystem = null,
    ) {}

    private static function removeComment(string $line): string
    {
        $result = '';
        $depth = 0;
        for ($index = 0; $index < strlen($line); $index++) {
            if ($line[$index] === '\\') {
                $start = $index;
                while (($line[$index] ?? '') === '\\') {
                    $index++;
                }
                $count = $index - $start;
                if ($count < 0) {
                    throw new LogicException('Backslash count must be non-negative');
                }
                if (($line[$index] ?? '') === '#') {
                    // Dividing a non-negative count by 2 yields a non-negative result.
                    // Dividing by 2 cannot cause division by zero or integer overflow.
                    // @mago-expect analysis:unhandled-thrown-type,unhandled-thrown-type,possibly-invalid-argument
                    $result .= str_repeat('\\', intdiv($count, num2: 2));
                    if (($count % 2) === 0) {
                        break;
                    }
                    $result .= '#';
                    continue;
                }
                $result .= str_repeat('\\', $count);
                $index--;
                continue;
            }
            if (
                ($line[$index] === '(' || $line[$index] === '{')
                && ($depth > 0 || $index > 0 && $line[$index - 1] === '$')
            ) {
                $depth++;
            } elseif (($line[$index] === ')' || $line[$index] === '}') && $depth > 0) {
                $depth--;
            }
            if ($line[$index] === '#' && $depth === 0) {
                break;
            }
            $result .= $line[$index];
        }
        return $result;
    }

    /**
     * @param array<int, string> $sources
     */
    private static function sourceLocation(array $sources, int $lineNumber): ?string
    {
        $location = null;
        foreach ($sources as $start => $path) {
            if ($start > $lineNumber) {
                break;
            }
            $location = $path . ':' . ($lineNumber - $start + 1);
        }
        return $location;
    }

    /** @return array{string, ?string} */
    private static function splitRecipe(string $line): array
    {
        for ($index = 0; $index < strlen($line); $index++) {
            if ($line[$index] === '\\') {
                $index++;
                continue;
            }
            if ($line[$index] === '#') {
                return [self::removeComment($line), null];
            }
            if ($line[$index] === ';') {
                return [self::removeComment(substr($line, offset: 0, length: $index)), substr($line, $index + 1)];
            }
        }
        return [self::removeComment($line), null];
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? [] : $words;
    }

    /** @throws MakefileErrorException */
    public function parse(): Makefile
    {
        $builder = new MakefileBuilder(!($this->configuration->noBuiltinRules ?? false), $this->output);
        $context = new EvaluationContext();
        $context->shell = $this->shell;
        $context->filesystem = $this->filesystem;
        $variables = &$context->variables;
        foreach ($this->defaults as $variable) {
            $variables[$variable->name] = $variable;
            if (in_array($variable->origin, ['environment', 'environment override'], true)) {
                $context->inheritedEnvironment[$variable->name] = $variable->expression;
            }
        }
        foreach ($this->overrides as $variable) {
            $variables[$variable->name] = $variable;
        }
        $inherited = ['MAKEFLAGS'];
        foreach ($variables as $variable) {
            if (in_array($variable->origin, ['environment', 'environment override', 'command line'], true)) {
                $inherited[] = $variable->name;
            }
        }
        $exports = new Exports($inherited);
        $context->exports = $exports;
        $context->evaluate =
            /** @throws MakefileErrorException */
            function (string $text, VariableExpander $expander) use ($builder, $exports): void {
                try {
                    $this->readRules(
                        $text,
                        $builder,
                        $expander->context->variables,
                        [],
                        $exports,
                        [],
                        $expander,
                        $expander->source,
                    );
                } catch (ParseException $error) {
                    throw new MakefileErrorException($error->reason, $expander->source);
                }
            };
        $scope = new VariableExpander($context, $this->output);
        if ($this->sources === []) {
            $this->readRules($this->source, $builder, $variables, [], $exports, [], $scope);
        } else {
            $lines = explode("\n", $this->source);
            $starts = array_keys($this->sources);
            foreach ($starts as $index => $start) {
                $this->readRules(
                    implode("\n", array_slice(
                        $lines,
                        $start - 1,
                        ($starts[$index + 1] ?? (count($lines) + 1)) - $start,
                    )),
                    $builder,
                    $variables,
                    [],
                    $exports,
                    [1 => $this->sources[$start]],
                    $scope,
                );
            }
        }
        $context->reading = false;
        return $builder->build(
            array_values($variables),
            $this->configuration->noBuiltinRules ?? false ? [] : $this->builtinRules,
            $exports,
            !($this->configuration->noBuiltinRules ?? false),
            $context,
        );
    }

    /** @return list<string> */
    private function matchingPaths(string $pattern): array
    {
        $paths = $this->files?->matching($pattern) ?? [];
        return $paths === [] ? [$pattern] : $paths;
    }

    /**
     * @param array<int, string> $sources
     */
    private function readDefinition(
        LineReader $reader,
        string $prefix,
        int $start,
        array $sources,
        ?string $evaluationSource,
    ): string {
        $lines = [];
        $depth = 1;
        while (($line = $reader->next($prefix, true)) !== null) {
            if (!str_starts_with($line, $prefix)) {
                $directive = trim(self::removeComment($line));
                $matches = [];
                if (preg_match('/^define(?:[ \t]+(?![:+?!=])\S|$)/', $directive) === 1) {
                    $depth++;
                } elseif (preg_match('/^endef(?:\s+(.*))?$/', $directive, $matches) === 1) {
                    /** @var array{string, 1?: string} $matches */
                    if (--$depth === 0) {
                        if (($matches[1] ?? '') !== '') {
                            $this->output?->writeWarning(
                                "extraneous text after 'endef' directive",
                                $evaluationSource ?? self::sourceLocation($sources, $reader->lineNumber),
                            );
                        }
                        return implode("\n", $lines);
                    }
                }
            }
            $lines[] = $line;
        }
        throw new ParseException($start, "missing 'endef', unterminated 'define'");
    }

    /**
     * @param array<string, Variable> $variables
     * @param list<string> $included
     * @param array<int, string> $sources
     * @throws ParseException
     * @throws MakefileErrorException
     */
    private function readLines(
        string $source,
        MakefileBuilder $builder,
        array &$variables,
        array $included,
        Exports $exports,
        array $sources,
        VariableExpander $scope,
        ?string $evaluationSource = null,
    ): void {
        $reader = new LineReader($source);
        $rule = null;
        $conditionals = new Conditionals();

        while (($line = $reader->next($variables['.RECIPEPREFIX']->expression[0] ?? "\t")) !== null) {
            $lineNumber = $reader->lineNumber;
            $location = $evaluationSource ?? self::sourceLocation($sources, $lineNumber);
            $expander = $scope->atSource($location);
            if (str_starts_with($line, $variables['.RECIPEPREFIX']->expression[0] ?? "\t")) {
                if (!$conditionals->active()) {
                    continue;
                }
                if ($rule === null) {
                    throw new ParseException($lineNumber, 'Recipe without a rule');
                }
                $rule->addRecipe(substr($line, offset: 1), $location);
                continue;
            }

            $uncommented = self::removeComment($line);
            if (trim($uncommented) === '') {
                continue;
            }

            if (
                !$conditionals->active()
                && preg_match('/^\s*(?:(?:override|export|unexport)\s+)*define\s+(?![:+?!=])/', $uncommented) === 1
            ) {
                $this->readDefinition(
                    $reader,
                    $variables['.RECIPEPREFIX']->expression[0] ?? "\t",
                    $lineNumber,
                    $sources,
                    $evaluationSource,
                );
                continue;
            }
            if ($conditionals->read($uncommented, $expander, $lineNumber) || !$conditionals->active()) {
                continue;
            }

            if ($rule !== null) {
                $builder->addRule($rule);
                $rule = null;
            }

            $uncommented = ltrim($uncommented);
            $matches = [];
            $export = null;
            $origin = 'file';
            $private = false;
            while (
                preg_match('/^\s*(override|private|export|unexport)(?:[ \t]+|$)(.*)$/s', $uncommented, $matches) === 1
            ) {
                /** @var array{string, 'override'|'private'|'export'|'unexport', string} $matches */
                if (preg_match('/^(?::::=|::=|:=|!=|\+=|\?=|=)/', ltrim($matches[2])) === 1) {
                    break;
                }
                if ($matches[1] === 'override') {
                    $origin = 'override';
                } elseif ($matches[1] === 'private') {
                    $private = true;
                } else {
                    $export = $matches[1] === 'export';
                }
                $uncommented = ltrim($matches[2]);
            }
            if (
                preg_match('/^define(?:[ \t]+(.*)|$)/s', $uncommented, $matches) === 1
                && preg_match('/^(?::::=|::=|:=|!=|\+=|\?=|=)/', ltrim($matches[1] ?? '')) !== 1
            ) {
                /** @var array{string, 1?: string} $matches */
                $header = Assignment::parse($matches[1] ?? '', allowWhitespace: true) ?? new Assignment(
                    trim($matches[1] ?? ''),
                    '=',
                    '',
                );
                $header = $header->resolveName($expander);
                if ($header->expression !== '') {
                    $this->output?->writeWarning("extraneous text after 'define' directive", $location);
                }
                $body = $this->readDefinition(
                    $reader,
                    $variables['.RECIPEPREFIX']->expression[0] ?? "\t",
                    $lineNumber,
                    $sources,
                    $evaluationSource,
                );
                new Assignment($header->name, $header->operator, $body)->apply(
                    $variables,
                    $origin,
                    $this->output,
                    $location,
                    $expander,
                    $private,
                );
                if ($header->name === 'MAKEFLAGS') {
                    $this->configuration?->updateMakeflags($variables, $expander);
                }
                if ($export !== null) {
                    $exports->set([$header->name], $export);
                }
                continue;
            }
            if (
                preg_match('/^undefine(?:[ \t]+(.*)|$)/s', $uncommented, $matches) === 1
                && preg_match('/^(?::::=|::=|:=|!=|\+=|\?=|=)/', ltrim($matches[1] ?? '')) !== 1
            ) {
                /** @var array{string, 1?: string} $matches */
                $name = new Assignment(trim($matches[1] ?? ''), '=', '')->resolveName($expander)->name;
                Assignment::undefine($variables, $name, $origin);
                continue;
            }
            if (preg_match('/^endef(?:\s|$)/', $uncommented) === 1 && Assignment::parse($uncommented) === null) {
                throw new ParseException($lineNumber, "extraneous 'endef'");
            }
            if ($export !== null && Assignment::parse($uncommented) === null) {
                $names = self::words($expander->expand($uncommented));
                $exports->set($names, $export);
                foreach ($names as $name) {
                    $variables[$name] ??= new Variable($name, '', false);
                }
                continue;
            }
            if (preg_match('/^\\.EXPORT_ALL_VARIABLES\\s*:/', $uncommented) === 1) {
                $exports->set([], true);
            }

            $assignment = Assignment::parse($uncommented);
            if ($assignment !== null) {
                $assignment = $assignment->resolveName($expander);
                if ($export !== null) {
                    $exports->set([$assignment->name], $export);
                }
                $assignment->apply($variables, $origin, $this->output, $location, $expander, $private);
                if ($assignment->name === 'MAKEFLAGS') {
                    $this->configuration?->updateMakeflags($variables, $expander);
                }
                continue;
            }

            if (preg_match('/^\s*(-?include|sinclude)(?:\s+(.*))?$/', $uncommented, $matches) === 1) {
                /** @var array{string, '-include'|'include'|'sinclude', 2?: string} $matches */
                $patterns = self::words($expander->expand($matches[2] ?? ''));
                foreach ($patterns as $pattern) {
                    foreach ($this->matchingPaths($pattern) as $path) {
                        $contents = $this->files?->read($path);
                        if ($contents === null) {
                            if ($matches[1] === 'include') {
                                throw new ParseException($lineNumber, "Included makefile `$path' not found");
                            }
                            continue;
                        }
                        if (in_array($path, $included, strict: true)) {
                            throw new ParseException($lineNumber, "Recursive include `$path'");
                        }
                        $this->readRules(
                            $contents,
                            $builder,
                            $variables,
                            [...$included, $path],
                            $exports,
                            [
                                1 => $path,
                            ],
                            $scope,
                        );
                    }
                }
                continue;
            }

            if (preg_match('/^vpath(?:[ \t]+(.*)|$)/s', $uncommented, $matches) === 1) {
                $builder->paths->define(self::words($expander->expand($matches[1] ?? '')));
                continue;
            }

            if (ScopedAssignment::read($uncommented, $builder->scopes, $expander, $this->output)) {
                continue;
            }

            [$header, $recipe] = self::splitRecipe($line);
            $expanded = $expander->expand($header);
            if (trim($expanded) === '' && $recipe === null) {
                continue;
            }
            if (!$scope->context->reading) {
                throw new MakefileErrorException('prerequisites cannot be defined in recipes', $location);
            }
            $rule = RuleSyntax::parse($expanded, $lineNumber, $location, $this->files);
            if ($recipe !== null) {
                $rule->addRecipe(ltrim($recipe), $location);
            }
        }

        $conditionals->finish($reader->lineNumber);
        if ($rule !== null) {
            $builder->addRule($rule);
        }
    }

    /**
     * @param array<string, Variable> $variables
     * @param list<string> $included
     * @param array<int, string> $sources
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private function readRules(
        string $source,
        MakefileBuilder $builder,
        array &$variables,
        array $included,
        Exports $exports,
        array $sources,
        VariableExpander $scope,
        ?string $evaluationSource = null,
    ): void {
        try {
            $this->readLines($source, $builder, $variables, $included, $exports, $sources, $scope, $evaluationSource);
        } catch (ParseException $error) {
            $location = $evaluationSource ?? self::sourceLocation($sources, $error->lineNumber);
            if ($location === null) {
                throw $error;
            }
            throw new MakefileErrorException($error->reason, $location);
        }
    }
}
