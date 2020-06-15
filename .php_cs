<?php

// Additional rules from https://github.com/stechstudio/Laravel-PHP-CS-Fixer

$finder = PhpCsFixer\Finder::create()
    ->notPath('bootstrap/cache')
    ->notPath('storage')
    ->notPath('vendor')
    ->in(__DIR__)
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return PhpCsFixer\Config::create()
    ->setLineEnding("\r\n")
    ->setRules([
        'psr0' => false,
        '@PSR1' => true,
        '@PSR2' => true,
        '@PHP71Migration' => true,
        '@PhpCsFixer' => true,
        'concat_space' => ['spacing' => 'one'],
        'phpdoc_separation' => false,
        'phpdoc_align' => ['align' => 'left'],
        //--
        'cast_spaces' => ['space' => 'none'],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'blank_line_after_namespace' => true,
        'braces' => true,
        'class_definition' => true,
        'elseif' => true,
        'function_declaration' => true,
        'indentation_type' => true,
        'line_ending' => true,
        'lowercase_constants' => true,
        'lowercase_keywords' => true,
        'method_argument_space' => [
            'ensure_fully_multiline' => true,
        ],
        'no_break_comment' => true,
        'no_closing_tag' => true,
        'no_spaces_after_function_name' => true,
        'no_spaces_inside_parenthesis' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'single_blank_line_at_eof' => true,
        'single_class_element_per_statement' => [
            'elements' => ['property'],
        ],
        'single_import_per_statement' => true,
        'single_line_after_imports' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'visibility_required' => true,
        'encoding' => true,
        'full_opening_tag' => true,
        'no_superfluous_phpdoc_tags' => false,
    ])
    ->setLineEnding("\r\n")
    ->setFinder($finder);
