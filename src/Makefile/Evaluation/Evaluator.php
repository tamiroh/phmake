<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Makefile\Evaluation;

use LogicException;
use Tamiroh\Phmake\Makefile\Builtins;
use Tamiroh\Phmake\Makefile\Expansion\EvaluationContext;
use Tamiroh\Phmake\Makefile\Expansion\LoadedObject\LoadedObjects;
use Tamiroh\Phmake\Makefile\Expansion\UndefinedVariable;
use Tamiroh\Phmake\Makefile\Expansion\VariableExpander;
use Tamiroh\Phmake\Makefile\IO\Filesystem;
use Tamiroh\Phmake\Makefile\IO\Guile;
use Tamiroh\Phmake\Makefile\IO\Output;
use Tamiroh\Phmake\Makefile\IO\Shell;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\ReadFile;
use Tamiroh\Phmake\Makefile\Reporting\ReportingOptions;
use Tamiroh\Phmake\Makefile\Rule\DependencySyntax;
use Tamiroh\Phmake\Makefile\Rule\FileName;
use Tamiroh\Phmake\Makefile\Rule\PatternRule;
use Tamiroh\Phmake\Makefile\RuleDefinitions;
use Tamiroh\Phmake\Makefile\Variable\Assignment;
use Tamiroh\Phmake\Makefile\Variable\Environment\Exports;
use Tamiroh\Phmake\Makefile\Variable\Variable;
use Tamiroh\Phmake\Parser\Ast;
use Tamiroh\Phmake\Parser\MakefileParser;
use Tamiroh\Phmake\Parser\ParseException;

use function array_values;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_starts_with;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

final readonly class Evaluator
{
    private Assignments $assignments;

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
        private LoadedObjects $loadedObjects = new LoadedObjects(),
        private ?Guile $guile = null,
    ) {
        $this->assignments = new Assignments($this->sources, $this->output, $this->configuration);
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
     *
     * @return array{Makefile, EvaluationContext}
     */
    public function evaluate(?Ast\MakefileNode $ast = null): array
    {
        $definitions = new RuleDefinitions(!($this->configuration->noBuiltinRules ?? false), $this->output);
        $context = new EvaluationContext();
        $context->loadedObjects = $this->loadedObjects;
        $context->guile = $this->guile;
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
        $context->reading->evaluate =
            /** @throws MakefileErrorException */
            function (string $text, VariableExpander $expander) use ($definitions, $exports): void {
                try {
                    $this->readRules(
                        $text,
                        $definitions,
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
            $this->readRules($text, $definitions, $variables, [], $exports, [], $scope, '<command-line>');
        }
        foreach (self::words($scope->variable('MAKEFILES') === null ? '' : $scope->expand('$(MAKEFILES)')) as $path) {
            $this->readFile(
                $this->sources->open($path, optional: true, defaultGoal: false),
                $definitions,
                $exports,
                $scope,
                [],
            );
        }
        if ($ast !== null) {
            $this->readFile(new ReadFile($ast->span->file, null, null), $definitions, $exports, $scope, [], $ast);
        } else {
            foreach ($this->sources->main as $path) {
                $this->readFile($this->sources->open($path, main: true), $definitions, $exports, $scope, []);
            }
        }
        // GNU make rereads its environment flags after reading all makefiles.
        if ($scope->variable('GNUMAKEFLAGS') === null) {
            UndefinedVariable::warn($scope, 'GNUMAKEFLAGS');
        }
        $this->configuration?->finishReading($variables, $scope);
        $context->reading->initial = false;
        return [
            $definitions->makefile(
                array_values($variables),
                $this->configuration->noBuiltinRules ?? false ? [] : $this->builtinRules,
                $exports,
                !($this->configuration->noBuiltinRules ?? false),
                $context,
            ),
            $context,
        ];
    }

    /**
     * @param array<string, Variable> $variables
     * @param array<int, string> $sources
     *
     * @throws ParseException
     */
    private function definition(
        SyntaxReader $reader,
        Ast\DefineHeaderNode $header,
        array $variables,
        VariableExpander $scope,
        array $sources,
        ?string $evaluationSource,
    ): string {
        [$body, $end] = $reader->definition(
            $header,
            $variables['.RECIPEPREFIX']->expression[0] ?? "\t",
            $scope->context->reading->posix,
        );
        if ($end !== null && $end->expression !== '') {
            $this->output?->writeWarning(
                "extraneous text after 'endef' directive",
                $evaluationSource ?? self::sourceLocation($sources, $end->span->startLine),
            );
        }
        return $body;
    }

    /**
     * @param array<string, Variable> $variables
     *
     * @throws ParseException
     * @throws MakefileErrorException
     */
    private function directive(
        Ast\DirectiveNode $node,
        RuleDefinitions $definitions,
        array &$variables,
        Exports $exports,
        VariableExpander $expander,
    ): void {
        switch ($node->directive) {
            case 'undefine':
                [$origin] = Assignments::modifiers($node->modifiers);
                Assignment::undefine(
                    $variables,
                    new Assignment($node->expression, '=', '')->resolveName($expander)->name,
                    $origin,
                );
                return;
            case 'endef':
                throw new ParseException($node->span->startLine, "extraneous 'endef'");
            case 'export':
            case 'unexport':
                $names = self::words($expander->expand($node->expression));
                $exports->set($names, $node->directive === 'export');
                foreach ($names as $name) {
                    $variables[$name] ??= new Variable($name, '', false);
                }
                return;
            case 'vpath':
                $definitions->searchPaths->define(self::words($expander->expand($node->expression)));
                return;
            case 'load':
            case '-load':
                foreach (self::words($expander->expand($node->expression)) as $name) {
                    $object = $expander->context->loadedObjects->load($name, $node->directive === '-load', $expander);
                    $contents = $this->sources->filesystem->read($object->path);
                    $this->sources->read[] = new ReadFile(
                        $object->path,
                        $contents['text'],
                        $contents['modifiedAt'],
                        $object->optional,
                        false,
                        $object->source,
                        !$object->keep,
                    );
                }
                return;
        }
        throw new LogicException('Unknown directive: ' . $node->directive);
    }

    /**
     * @param list<string> $included
     *
     * @throws MakefileErrorException
     * @throws ParseException
     */
    private function readFile(
        ReadFile $file,
        RuleDefinitions $definitions,
        Exports $exports,
        VariableExpander $scope,
        array $included,
        ?Ast\MakefileNode $ast = null,
    ): void {
        $source = $ast ?? $file->text;
        if ($source === null) {
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
                $source,
                $definitions,
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
        Ast\MakefileNode $ast,
        RuleDefinitions $definitions,
        array &$variables,
        array $included,
        Exports $exports,
        array $sources,
        VariableExpander $scope,
        ?string $evaluationSource = null,
    ): void {
        $reader = new SyntaxReader($ast);
        $rule = null;
        $conditionals = new Conditionals();
        while (
            ($node = $reader->next(
                $variables['.RECIPEPREFIX']->expression[0] ?? "\t",
                $scope->context->reading->posix,
                $rule !== null,
            )) !== null
        ) {
            $location = $evaluationSource ?? self::sourceLocation($sources, $node->span->startLine);
            $expander = $scope->atSource($location);
            if ($node instanceof Ast\RecipeNode) {
                if ($conditionals->active()) {
                    $rule?->addRecipe($node->expression, $location);
                }
                continue;
            }
            if ($node instanceof Ast\TriviaNode) {
                continue;
            }
            if ($node instanceof Ast\ConditionalDirectiveNode) {
                $conditionals->read($node, $expander);
                continue;
            }
            if (!$conditionals->active()) {
                if ($node instanceof Ast\DefineHeaderNode && $node->skipWhenInactive) {
                    $this->definition($reader, $node, $variables, $scope, $sources, $evaluationSource);
                }
                continue;
            }
            if ($rule !== null) {
                $definitions->addRule($rule->definition());
                $rule = null;
            }
            if ($node instanceof Ast\DefineHeaderNode) {
                $header = Assignments::assignment($node->assignment)->resolveName($expander);
                if ($header->expression !== '') {
                    $this->output?->writeWarning("extraneous text after 'define' directive", $location);
                }
                $body = $this->definition($reader, $node, $variables, $scope, $sources, $evaluationSource);
                $this->assignments->store(
                    new Assignment($header->name, $header->operator, $body),
                    $node->assignment->modifiers,
                    $variables,
                    $exports,
                    $expander,
                    definition: true,
                );
                continue;
            }
            if ($node instanceof Ast\AssignmentNode) {
                if ($node->exportAll) {
                    $exports->set([], true);
                }
                $this->assignments->store(
                    Assignments::assignment($node)->resolveName($expander),
                    $node->modifiers,
                    $variables,
                    $exports,
                    $expander,
                );
                continue;
            }
            if ($node instanceof Ast\IncludeNode) {
                foreach (self::words($expander->expand($node->expression)) as $pattern) {
                    foreach ($this->sources->matching($pattern) as $path) {
                        $this->readFile(
                            $this->sources->open(
                                $path,
                                $node->directive !== 'include',
                                $this->sources->defaultGoal,
                                $location,
                            ),
                            $definitions,
                            $exports,
                            $scope,
                            $included,
                        );
                    }
                }
                continue;
            }
            if ($node instanceof Ast\DirectiveNode) {
                $this->directive($node, $definitions, $variables, $exports, $expander);
                continue;
            }
            if (str_starts_with($node->raw, $variables['.RECIPEPREFIX']->expression[0] ?? "\t")) {
                throw new ParseException($node->span->startLine, 'Recipe without a rule');
            }
            if ($node instanceof Ast\TargetAssignmentNode) {
                if ($node->exportAll) {
                    $exports->set([], true);
                }
                [$origin, $private, $export] = Assignments::modifiers($node->assignment->modifiers);
                $assignment = Assignments::assignment($node->assignment);
                foreach (DependencySyntax::words($expander->expand($node->targets)) as $target) {
                    $definitions->targetVariables->define(
                        FileName::normalize($target),
                        $assignment,
                        $expander,
                        $origin,
                        $private,
                        $export,
                        $this->output,
                    );
                }
                continue;
            }
            if ($node instanceof Ast\RuleNode || $node instanceof Ast\ExpressionNode) {
                if ($node instanceof Ast\RuleNode && $node->exportAll) {
                    $exports->set([], true);
                }
                $rule = $this->rule($node, $definitions, $scope, $expander, $evaluationSource);
                continue;
            }
            throw new LogicException('Unresolved syntax reached evaluation');
        }
        $conditionals->finish($reader->lineNumber());
        if ($rule !== null) {
            $definitions->addRule($rule->definition());
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
        string|Ast\MakefileNode $source,
        RuleDefinitions $definitions,
        array &$variables,
        array $included,
        Exports $exports,
        array $sources,
        VariableExpander $scope,
        ?string $evaluationSource = null,
    ): void {
        try {
            $this->readLines(
                $source instanceof Ast\MakefileNode
                    ? $source
                    : new MakefileParser()->parse($source, $sources[1] ?? $evaluationSource ?? '<input>'),
                $definitions,
                $variables,
                $included,
                $exports,
                $sources,
                $scope,
                $evaluationSource,
            );
        } catch (ParseException $error) {
            $location = $evaluationSource ?? self::sourceLocation($sources, $error->lineNumber);
            if ($location === null) {
                throw $error;
            }
            throw new MakefileErrorException($error->reason, $location);
        }
    }

    /**
     * @throws ParseException
     * @throws MakefileErrorException
     */
    private function rule(
        Ast\RuleNode|Ast\ExpressionNode $node,
        RuleDefinitions $definitions,
        VariableExpander $scope,
        VariableExpander $expander,
        ?string $evaluationSource,
    ): ?Rule {
        $expanded = $expander->expand($node->header);
        if (trim($expanded, " \t\n\r\0\x0B\f") === '' && $node->inlineRecipe === null) {
            return null;
        }
        if (!$scope->context->reading->initial) {
            throw new MakefileErrorException(
                'prerequisites cannot be defined in recipes',
                $scope->secondary ? null : $evaluationSource,
                contextual: !$scope->secondary,
            );
        }
        if (DependencySyntax::delimiter($expanded, ':') === null) {
            if (
                ($scope->context->variables['.RECIPEPREFIX']->expression[0] ?? "\t") === "\t"
                && str_starts_with($node->raw, '        ')
            ) {
                throw new ParseException(
                    $node->span->startLine,
                    'missing separator (did you mean TAB instead of 8 spaces?)',
                );
            }
            if (preg_match('/^\s*ifn?eq\S/', $node->raw) === 1) {
                throw new ParseException(
                    $node->span->startLine,
                    'missing separator (ifeq/ifneq must be followed by whitespace)',
                );
            }
        }
        $rule = RuleSyntax::parse($expanded, $node->span->startLine, $expander->source, $this->sources->filesystem);
        if (in_array('.POSIX', $rule->targetNames, true)) {
            $scope->context->reading->posix = true;
            foreach (Builtins::posixVariables() as $variable) {
                if (($scope->context->variables[$variable->name]->origin ?? 'default') === 'default') {
                    $scope->context->variables[$variable->name] = $variable;
                }
            }
        }
        if ($this->sources->defaultGoal) {
            $definitions->selectDefault($rule->definition(), $scope);
        }
        if ($node->inlineRecipe !== null) {
            $rule->addRecipe(ltrim($node->inlineRecipe->expression), $expander->source);
        }
        return $rule;
    }
}
