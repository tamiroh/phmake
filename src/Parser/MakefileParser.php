<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use LogicException;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Evaluation\Assignment;
use Tamiroh\Phmake\Makefile\Evaluation\Environment\Exports;
use Tamiroh\Phmake\Makefile\Evaluation\EvaluationContext;
use Tamiroh\Phmake\Makefile\Evaluation\Module\Modules;
use Tamiroh\Phmake\Makefile\Evaluation\UndefinedVariable;
use Tamiroh\Phmake\Makefile\Evaluation\Variable;
use Tamiroh\Phmake\Makefile\Evaluation\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Reporting\ReportingOptions;
use Tamiroh\Phmake\Makefile\Rule\DependencySyntax;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Parser\Source\LineReader;
use Tamiroh\Phmake\Parser\Source\MakefileSources;
use Tamiroh\Phmake\Parser\Source\ReadFile;
use Tamiroh\Phmake\Parser\Syntax\Conditionals;
use Tamiroh\Phmake\Parser\Syntax\RuleSyntax;
use Tamiroh\Phmake\Parser\Syntax\ScopedAssignment;

use function array_values;
use function implode;
use function in_array;
use function intdiv;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

final readonly class MakefileParser
{
    /**
     * @param list<Variable> $defaults
     * @param array<string, Variable> $overrides
     * @param list<PatternRule> $builtinRules
     */
    public function __construct(
        private MakefileSources $sources,
        private array $defaults = [],
        private array $overrides = [],
        private array $builtinRules = [],
        private ?Output $output = null,
        private ?Configuration $configuration = null,
        private ?Shell $shell = null,
        private ?Filesystem $filesystem = null,
        private ReportingOptions $reporting = new ReportingOptions(),
        private Modules $modules = new Modules(),
    ) {}

    /**
     * @pure
     */
    private static function removeComment(string $line): string
    {
        if (!str_contains($line, '#')) {
            return $line;
        }
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
                if (($line[$index] ?? '') === '#' && $depth === 0) {
                    /**
                     * Dividing a non-negative count by 2 yields a non-negative result.
                     * Dividing by 2 cannot cause division by zero or integer overflow.
                     *
                     * @mago-expect analysis:unhandled-thrown-type,unhandled-thrown-type,possibly-invalid-argument
                     */
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
     * @pure
     *
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

    /**
     * @pure
     *
     * @return array{string, ?string}
     */
    private static function splitRecipe(string $line): array
    {
        if (!str_contains($line, ';')) {
            return [self::removeComment($line), null];
        }
        $depth = 0;
        for ($index = 0; $index < strlen($line); $index++) {
            if ($line[$index] === '\\') {
                $index++;
                continue;
            }
            if ($line[$index] === '$' && isset($line[$index + 1])) {
                if ($line[$index + 1] === '(' || $line[$index + 1] === '{') {
                    $depth++;
                }
                $index++;
                continue;
            }
            if (($line[$index] === '(' || $line[$index] === '{') && $depth > 0) {
                $depth++;
            } elseif (($line[$index] === ')' || $line[$index] === '}') && $depth > 0) {
                $depth--;
            }
            if ($depth > 0) {
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

    /**
     * @pure
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? [] : $words;
    }

    /**
     * @throws MakefileErrorException
     * @throws ParseException
     */
    public function parse(): Makefile
    {
        $builder = new MakefileBuilder(!($this->configuration->noBuiltinRules ?? false), $this->output);
        $context = new EvaluationContext();
        $context->modules = $this->modules;
        $context->reporting = $this->reporting;
        $context->shell = $this->shell;
        $context->filesystem = $this->filesystem;
        $variables = &$context->variables;
        foreach ($this->defaults as $variable) {
            $variables[$variable->name] = $variable;
            if (in_array($variable->origin, ['environment', 'environment override'], true)) {
                $context->environment->inherited[$variable->name] = $variable->expression;
            }
        }
        foreach ($this->overrides as $variable) {
            $variables[$variable->name] = $variable;
        }
        $inherited = ['MAKEFLAGS', 'MAKEFILES'];
        foreach ($variables as $variable) {
            if (in_array($variable->origin, ['environment', 'environment override'], true)) {
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
        $variables['MAKEFILE_LIST'] = new Variable('MAKEFILE_LIST', '', false);
        $variables['.INCLUDE_DIRS'] = new Variable(
            '.INCLUDE_DIRS',
            implode(' ', $this->sources->directories()),
            false,
            'default',
        );
        foreach ($this->sources->evaluations as $text) {
            $this->readRules($text, $builder, $variables, [], $exports, [], $scope, '<command-line>');
        }
        foreach (self::words($scope->variable('MAKEFILES') === null ? '' : $scope->expand('$(MAKEFILES)')) as $path) {
            $this->readFile(
                $this->sources->open($path, optional: true, defaultGoal: false),
                $builder,
                $exports,
                $scope,
                [],
            );
        }
        foreach ($this->sources->main as $path) {
            $this->readFile($this->sources->open($path, main: true), $builder, $exports, $scope, []);
        }
        // GNU make rereads its environment flags after reading all makefiles.
        if ($scope->variable('GNUMAKEFLAGS') === null) {
            UndefinedVariable::warn($scope, 'GNUMAKEFLAGS');
        }
        $this->configuration?->finishReading($variables, $scope);
        $context->reading = false;
        return $builder->build(
            array_values($variables),
            $this->configuration->noBuiltinRules ?? false ? [] : $this->builtinRules,
            $exports,
            !($this->configuration->noBuiltinRules ?? false),
            $context,
        );
    }

    /**
     * @param array<int, string> $sources
     *
     * @throws ParseException
     */
    private function readDefinition(
        LineReader $reader,
        string $prefix,
        int $start,
        array $sources,
        ?string $evaluationSource,
        bool $posix,
    ): string {
        $lines = [];
        $depth = 1;
        while (($line = $reader->next($prefix, true, $posix)) !== null) {
            if (!str_starts_with($line, $prefix)) {
                $directive = trim(self::removeComment($line));
                $matches = [];
                if (preg_match('/^define(?:[ \t]+(?![:+?!=])\S|$)/', $directive) === 1) {
                    $depth++;
                } elseif (preg_match('/^endef(?:\s+(.*))?$/', $directive, $matches) === 1) {
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
     * @param list<string> $included
     *
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private function readFile(
        ReadFile $file,
        MakefileBuilder $builder,
        Exports $exports,
        VariableExpander $scope,
        array $included,
    ): void {
        if ($file->text === null) {
            if (!$file->optional && $file->source === null && !$this->sources->restarted) {
                $this->output?->writeWarning($file->path . ': ' . ($file->error ?? 'No such file or directory'));
            }
            return;
        }
        if (in_array($file->path, $included, true)) {
            throw new MakefileErrorException("Recursive include `{$file->path}'", $file->source);
        }
        $scope->context->variables['MAKEFILE_LIST'] = new Variable(
            'MAKEFILE_LIST',
            trim($scope->expand('$(MAKEFILE_LIST)') . ' ' . $file->path),
            false,
        );
        $defaultGoal = $this->sources->defaultGoal;
        $this->sources->defaultGoal = $file->defaultGoal;
        try {
            $this->readRules(
                str_starts_with($file->text, "\xEF\xBB\xBF") ? substr($file->text, 3) : $file->text,
                $builder,
                $scope->context->variables,
                [...$included, $file->path],
                $exports,
                [1 => $file->displayPath ?? $file->path],
                $scope,
            );
        } finally {
            $this->sources->defaultGoal = $defaultGoal;
        }
    }

    /**
     * @param array<string, Variable> $variables
     * @param list<string> $included
     * @param array<int, string> $sources
     *
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

        while (
            ($line = $reader->next(
                $variables['.RECIPEPREFIX']->expression[0] ?? "\t",
                posix: $scope->context->posix,
                hasRule: $rule !== null,
            )) !== null
        ) {
            $lineNumber = $reader->lineNumber;
            $location = $evaluationSource ?? self::sourceLocation($sources, $lineNumber);
            $expander = $scope->atSource($location);
            if ($rule !== null && str_starts_with($line, $variables['.RECIPEPREFIX']->expression[0] ?? "\t")) {
                if (!$conditionals->active()) {
                    continue;
                }
                $rule->addRecipe(substr($line, offset: 1), $location);
                continue;
            }

            $uncommented = self::removeComment($line);
            if (trim($uncommented, " \t\n\r\0\x0B\f") === '') {
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
                    $scope->context->posix,
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
                /** @var array{non-falsy-string, 'override'|'private'|'export'|'unexport', string} $matches */
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
                    $scope->context->posix,
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
                    $this->configuration?->updateMakeflags($variables, $expander, $origin);
                    $variables['.INCLUDE_DIRS'] = new Variable(
                        '.INCLUDE_DIRS',
                        implode(' ', $this->sources->directories()),
                        false,
                        'default',
                    );
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
                    $this->configuration?->updateMakeflags($variables, $expander, $origin);
                    $variables['.INCLUDE_DIRS'] = new Variable(
                        '.INCLUDE_DIRS',
                        implode(' ', $this->sources->directories()),
                        false,
                        'default',
                    );
                }
                continue;
            }

            if (preg_match('/^\s*(-?include|sinclude)(?:\s+(.*))?$/', $uncommented, $matches) === 1) {
                foreach (self::words($expander->expand($matches[2] ?? '')) as $pattern) {
                    foreach ($this->sources->matching($pattern) as $path) {
                        $this->readFile(
                            $this->sources->open(
                                $path,
                                $matches[1] !== 'include',
                                $this->sources->defaultGoal,
                                $location,
                            ),
                            $builder,
                            $exports,
                            $scope,
                            $included,
                        );
                    }
                }
                continue;
            }

            if (preg_match('/^(-?load)(?:[ \t]+(.*)|$)/s', $uncommented, $matches) === 1) {
                foreach (self::words($expander->expand($matches[2] ?? '')) as $name) {
                    $module = $scope->context->modules->load($name, $matches[1] === '-load', $expander);
                    $contents = $this->sources->files->read($module->path);
                    $this->sources->read[] = new ReadFile(
                        $module->path,
                        $contents->text,
                        $contents->modifiedAt,
                        $module->optional,
                        false,
                        $module->source,
                        !$module->keep,
                    );
                }
                continue;
            }

            if (preg_match('/^vpath(?:[ \t]+(.*)|$)/s', $uncommented, $matches) === 1) {
                $builder->paths->define(self::words($expander->expand($matches[1] ?? '')));
                continue;
            }

            if (str_starts_with($line, $variables['.RECIPEPREFIX']->expression[0] ?? "\t")) {
                throw new ParseException($lineNumber, 'Recipe without a rule');
            }
            if (ScopedAssignment::read($uncommented, $builder->scopes, $expander, $this->output)) {
                continue;
            }

            [$header, $recipe] = self::splitRecipe($line);
            $expanded = $expander->expand($header);
            if (trim($expanded, " \t\n\r\0\x0B\f") === '' && $recipe === null) {
                continue;
            }
            if (!$scope->context->reading) {
                throw new MakefileErrorException(
                    'prerequisites cannot be defined in recipes',
                    $scope->secondary ? null : $evaluationSource,
                    contextual: !$scope->secondary,
                );
            }
            if (DependencySyntax::delimiter($expanded, ':') === null) {
                if (
                    ($variables['.RECIPEPREFIX']->expression[0] ?? "\t") === "\t"
                    && str_starts_with($line, '        ')
                ) {
                    throw new ParseException($lineNumber, 'missing separator (did you mean TAB instead of 8 spaces?)');
                }
                if (preg_match('/^\s*ifn?eq\S/', $line) === 1) {
                    throw new ParseException(
                        $lineNumber,
                        'missing separator (ifeq/ifneq must be followed by whitespace)',
                    );
                }
            }
            $rule = RuleSyntax::parse($expanded, $lineNumber, $location, $this->sources->files);
            if (in_array('.POSIX', $rule->targetNames, true)) {
                $scope->context->posix = true;
                foreach (Builtins::posixVariables() as $variable) {
                    if (($variables[$variable->name]->origin ?? 'default') === 'default') {
                        $variables[$variable->name] = $variable;
                    }
                }
            }
            if ($this->sources->defaultGoal) {
                $builder->selectDefault($rule, $scope);
            }
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
     *
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
