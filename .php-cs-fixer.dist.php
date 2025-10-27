<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'assets'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
;

return (new PhpCsFixer\Config())
    ->setRules([
        // PSR-12 base
        '@PSR12' => true,
        '@Symfony' => true,

        // Strict types
        'declare_strict_types' => true,

        // Array syntax
        'array_syntax' => ['syntax' => 'short'],

        // Import ordering
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],

        // Strict comparison
        'strict_comparison' => true,
        'strict_param' => true,

        // No unused imports
        'no_unused_imports' => true,

        // Trailing comma in multiline
        'trailing_comma_in_multiline' => true,

        // Void return type
        'void_return' => true,

        // Native function invocation
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
        ],

        // No superfluous phpdoc tags
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => false,
        ],

        // Phpdoc alignment
        'phpdoc_align' => ['align' => 'left'],

        // Single line throw
        'single_line_throw' => false,

        // Modernize code
        'modernize_types_casting' => true,
        'modernize_strpos' => true,

        // Explicit string variable declarations
        'explicit_string_variable' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
