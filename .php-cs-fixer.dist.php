<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples'])
    ->exclude(['xcapher', 'Error'])
    ->append([__FILE__]);

return new Config()
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        '@PHP8x5Migration' => true,
        '@PHP8x5Migration:risky' => true,
        '@PHPUnit11x0Migration:risky' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'native_constant_invocation' => ['scope' => 'namespaced', 'strict' => true],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'phpdoc_align' => ['align' => 'left'],
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
    ])
    ->setFinder($finder);
