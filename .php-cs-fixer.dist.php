<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        'encoding' => true,
        'full_opening_tag' => true,
        'no_break_comment' => true,
        'no_unreachable_default_argument_value' => true,
        'php_unit_data_provider_static' => ['force' => true],
        'php_unit_assert_new_names' => true,
        'php_unit_expectation' => ['target' => '8.4'],
        'php_unit_dedicate_assert_internal_type' => ['target' => '7.5'],
        'php_unit_namespaced' => ['target' => '6.0'],
        'php_unit_dedicate_assert' => ['target' => '5.6'],
        'php_unit_mock' => ['target' => '5.5'],
        'php_unit_no_expectation_annotation' => ['target' => '4.3'],
    ])
    ->setFinder(
        (new Finder())
            ->in(__DIR__)
    );
