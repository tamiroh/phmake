<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use LogicException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

use function array_values;
use function in_array;
use function intdiv;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

final readonly class MakefileParser
{
    public function __construct(
        private string $source,
        private ?SourceFiles $files = null,
    ) {}

    private static function removeComment(string $line): string
    {
        $result = '';
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
            if ($line[$index] === '#') {
                break;
            }
            $result .= $line[$index];
        }
        return $result;
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
        $builder = new MakefileBuilder();
        $variables = [];
        $this->readRules($this->source, $builder, $variables, []);
        return $builder->build(array_values($variables));
    }

    /** @return list<string> */
    private function matchingPaths(string $pattern): array
    {
        $paths = $this->files?->matching($pattern) ?? [];
        return $paths === [] ? [$pattern] : $paths;
    }

    /**
     * @param array<string, Variable> $variables
     * @param list<string> $included
     * @throws MakefileErrorException
     */
    private function readRules(string $source, MakefileBuilder $builder, array &$variables, array $included): void
    {
        $reader = new LineReader($source);
        $rule = null;

        while (($line = $reader->next()) !== null) {
            $lineNumber = $reader->lineNumber;
            if (str_starts_with($line, "\t")) {
                if ($rule === null) {
                    throw new ParseException($lineNumber, 'Recipe without a rule');
                }
                $rule->addRecipe(substr($line, offset: 1));
                continue;
            }

            $uncommented = self::removeComment($line);
            if (trim($uncommented) === '') {
                continue;
            }

            if ($rule !== null) {
                $builder->addRule($rule);
                $rule = null;
            }

            $matches = [];
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(:=|=)(.*)$/s', $uncommented, $matches) === 1) {
                /** @var array{string, non-empty-string, ':='|'=', string} $matches */
                $value = ltrim($matches[3]);
                $variables[$matches[1]] = new Variable(
                    $matches[1],
                    $matches[2] === ':=' ? new VariableExpander(array_values($variables))->expand($value) : $value,
                    $matches[2] === '=',
                );
                continue;
            }

            if (preg_match('/^\s*(-?include|sinclude)\s+(.+)$/', $uncommented, $matches) === 1) {
                /** @var array{non-falsy-string, '-include'|'include'|'sinclude', non-empty-string} $matches */
                $patterns = self::words(new VariableExpander(array_values($variables))->expand($matches[2]));
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
                        $this->readRules($contents, $builder, $variables, [...$included, $path]);
                    }
                }
                continue;
            }

            [$header, $recipe] = self::splitRecipe($line);
            $expanded = new VariableExpander(array_values($variables))->expand($header);
            $colon = strpos($expanded, needle: ':');
            if ($colon === false) {
                throw new ParseException($lineNumber, 'missing separator');
            }

            $dependencies = substr($expanded, $colon + 1);
            $names = self::words(substr($expanded, offset: 0, length: $colon));
            if (
                $names === []
                || preg_match('/[:=|&]/', substr($expanded, offset: 0, length: $colon) . $dependencies) === 1
            ) {
                throw new ParseException($lineNumber, 'Unsupported rule syntax');
            }
            $prerequisites = [];
            foreach (self::words($dependencies) as $dependency) {
                foreach ($this->matchingPaths($dependency) as $path) {
                    $prerequisites[] = $path;
                }
            }
            $rule = new Rule($names, $prerequisites, $lineNumber);
            if ($recipe !== null) {
                $rule->addRecipe(ltrim($recipe));
            }
        }

        if ($rule !== null) {
            $builder->addRule($rule);
        }
    }
}
