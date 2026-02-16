<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    // Exclude DriverInterface.php - untyped params required for Laravel Queue interface compatibility
    ->notPath('Contracts/DriverInterface.php')
    // Exclude WorkerSupervisorTest — namespace-level function overrides require
    // unqualified calls to recurse into the override, and qualified \calls to
    // reach the real function. native_function_invocation strips the \ prefix.
    ->notPath('Unit/Core/WorkerSupervisorTest.php');

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        // ============================================
        // PER-CS 3.0 Base Ruleset
        // ============================================
        '@PER-CS3.0' => true,
        '@PER-CS3.0:risky' => true,

        // ============================================
        // Import Organization
        // ============================================
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // ============================================
        // Array Formatting
        // ============================================
        'array_syntax' => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],
        'whitespace_after_comma_in_array' => [
            'ensure_single_space' => true,
        ],

        // ============================================
        // Operators & Spacing
        // ============================================
        'not_operator_with_successor_space' => false,
        'unary_operator_spaces' => [
            'only_dec_inc' => false,
        ],
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => [
                '=>' => 'single_space',
                '|' => 'no_space',
                '&' => 'no_space',
            ],
        ],
        'concat_space' => ['spacing' => 'one'],
        'operator_linebreak' => [
            'only_booleans' => false,
            'position' => 'beginning',
        ],

        // ============================================
        // Control Structures
        // ============================================
        'blank_line_before_statement' => [
            'statements' => [
                'break',
                'continue',
                'declare',
                'return',
                'throw',
                'try',
                'yield',
                'yield_from',
            ],
        ],
        'control_structure_braces' => true,
        'control_structure_continuation_position' => [
            'position' => 'same_line',
        ],
        'no_useless_else' => true,
        'no_useless_return' => true,
        'simplified_if_return' => true,

        // ============================================
        // Class Structure
        // ============================================
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ],
            'sort_algorithm' => 'none',
        ],
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'one',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'none',
                'case' => 'none',
            ],
        ],
        'single_trait_insert_per_statement' => true,
        'class_definition' => [
            'single_line' => true,
            'space_before_parenthesis' => false,
        ],
        'ordered_interfaces' => true,

        // ============================================
        // Type Declarations (PHP 8.4+)
        // ============================================
        'nullable_type_declaration' => [
            'syntax' => 'question_mark',
        ],
        'nullable_type_declaration_for_default_null_value' => [
            'use_nullable_type_declaration' => true,
        ],
        'ordered_types' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'types_spaces' => [
            'space' => 'none',
        ],
        'phpdoc_to_param_type' => true,
        'phpdoc_to_return_type' => true,
        'void_return' => true,
        'phpdoc_to_property_type' => true,
        'fully_qualified_strict_types' => [
            'import_symbols' => true,
        ],

        // ============================================
        // Native Function Invocation (Performance)
        // ============================================
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],

        // ============================================
        // Modern PHP Features (8.x)
        // ============================================
        'modernize_types_casting' => true,
        'use_arrow_functions' => true,
        'long_to_shorthand_operator' => true,
        'modernize_strpos' => true,
        'get_class_to_class_keyword' => true,
        'static_lambda' => true,

        // ============================================
        // Strict Mode
        // ============================================
        'strict_comparison' => true,
        'strict_param' => true,
        'declare_strict_types' => true,

        // ============================================
        // PHPDoc
        // ============================================
        'phpdoc_align' => [
            'align' => 'left',
        ],
        'phpdoc_order' => [
            'order' => ['param', 'return', 'throws'],
        ],
        'phpdoc_separation' => [
            'groups' => [
                ['param', 'return'],
                ['throws'],
                ['deprecated', 'see', 'since'],
            ],
        ],
        'phpdoc_scalar' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'phpdoc_var_without_name' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_to_comment' => [
            'ignored_tags' => ['todo', 'var'],
        ],
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => false,
        ],
        'general_phpdoc_tag_rename' => [
            'replacements' => [
                'inheritDocs' => 'inheritDoc',
            ],
        ],

        // ============================================
        // Method & Function Arguments
        // ============================================
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'after_heredoc' => true,
        ],
        'function_declaration' => [
            'closure_function_spacing' => 'one',
            'closure_fn_spacing' => 'none',
        ],

        // ============================================
        // Comments & Whitespace
        // ============================================
        'single_line_comment_style' => [
            'comment_types' => ['hash'],
        ],
        'multiline_comment_opening_closing' => true,
        'no_trailing_whitespace_in_comment' => true,

        // ============================================
        // Cleanup
        // ============================================
        'no_empty_statement' => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute',
                'case',
                'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'switch',
                'throw',
                'use',
            ],
        ],
        'no_whitespace_in_blank_line' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
