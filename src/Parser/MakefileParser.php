<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\Parser;

use LogicException;
use Tamiroh\Phmake\Makefile\Makefile;
use Tamiroh\Phmake\Makefile\MakefileErrorException;
use Tamiroh\Phmake\Makefile\Variable;
use Tamiroh\Phmake\Makefile\VariableExpander;

final readonly class MakefileParser
{
    public function __construct(
        private string $source,
    ) {}

    /** @throws MakefileErrorException */
    public function parse(): Makefile
    {
        $builder = new MakefileBuilder();
        $reader = new LineReader($this->source);
        $rule = null;
        $variables = [];

        while (($line = $reader->next()) !== null) {
            $lineNumber = $reader->lineNumber;
            if (str_starts_with($line, "\t")) {
                if ($rule === null) {
                    throw new ParseException($lineNumber, 'Recipe without a rule');
                }
                $rule->addRecipe(substr($line, 1));
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

            [$header, $recipe] = self::splitRecipe($line);
            $expanded = new VariableExpander(array_values($variables))->expand($header);
            $colon = strpos($expanded, ':');
            if ($colon === false) {
                throw new ParseException($lineNumber, 'missing separator');
            }

            $dependencies = substr($expanded, $colon + 1);
            $names = self::words(substr($expanded, 0, $colon));
            if ($names === [] || preg_match('/[:=%|&]/', substr($expanded, 0, $colon) . $dependencies) === 1) {
                throw new ParseException($lineNumber, 'Unsupported rule syntax');
            }
            $rule = new Rule($names, self::words($dependencies), $lineNumber);
            if ($recipe !== null) {
                $rule->addRecipe(ltrim($recipe));
            }
        }

        if ($rule !== null) {
            $builder->addRule($rule);
        }

        return $builder->build(array_values($variables));
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
                return [self::removeComment(substr($line, 0, $index)), substr($line, $index + 1)];
            }
        }
        return [self::removeComment($line), null];
    }

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
                    $result .= str_repeat('\\', intdiv($count, 2));
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

    /** @return list<string> */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? [] : $words;
    }
}
