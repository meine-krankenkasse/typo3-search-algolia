<?php

/**
 * This file represents the configuration for Code Sniffing PSR-2-related
 * automatic checks of coding guidelines
 * Install @fabpot's great php-cs-fixer tool via
 *
 *  $ composer global require friendsofphp/php-cs-fixer
 *
 * And then simply run
 *
 *  $ php-cs-fixer fix
 *
 * For more information read:
 *  http://www.php-fig.org/psr/psr-2/
 *  http://cs.sensiolabs.org
 */

if (PHP_SAPI !== 'cli') {
    die('This script supports command line usage only. Please check your command.');
}

$header = <<<EOF
This file is part of the package meine-krankenkasse/typo3-search-algolia.

For the full copyright and license information, please read the
LICENSE file that was distributed with this source code.
EOF;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12'                          => true,
        '@PER-CS3x0'                      => true,
        '@Symfony'                        => true,

        // @PER-CS3x0's operator_linebreak default (all binary operators) is
        // narrowed by @Symfony below to only_booleans => true. Left as-is
        // deliberately, no override for operator_linebreak follows, unlike
        // trailing_comma_in_multiline below, this narrowing is not an
        // oversight. Checked against friendsofphp/php-cs-fixer 3.95
        // (RuleSet/Sets/PERCS3x0Set.php, SymfonySet.php,
        // Fixer/Operator/OperatorLinebreakFixer.php) on 2026-08-27,
        // re-derive from those sources if this stops matching a future
        // major version.

        // Additional custom rules
        // @PER-CS2x0 includes 'arguments' in trailing_comma_in_multiline, but
        // @Symfony (applied after it in this rule set) overrides the same
        // fixer with a narrower element list that drops 'arguments' again,
        // so multi-line function/method CALLS silently never got a trailing
        // comma enforced. Restore the full @PER-CS2x0 element list explicitly,
        // including 'after_heredoc' (this rule overrides, not merges, so both
        // options must be repeated here). Checked against
        // friendsofphp/php-cs-fixer 3.95 (RuleSet/Sets/PERCS2x0Set.php,
        // SymfonySet.php, Fixer/ControlStructure/TrailingCommaInMultilineFixer.php)
        // on 2026-08-27, re-derive from those sources if this stops matching
        // a future major version.
        'trailing_comma_in_multiline'     => [
            'elements'      => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters'],
            'after_heredoc' => true,
        ],
        'declare_strict_types'            => true,
        'concat_space'                    => [
            'spacing' => 'one',
        ],
        'header_comment'                  => [
            'header'       => $header,
            'comment_type' => 'PHPDoc',
            'location'     => 'after_open',
            'separate'     => 'both',
        ],
        'phpdoc_to_comment'               => false,
        'phpdoc_no_alias_tag'             => false,
        'phpdoc_annotation_without_dot'   => false,
        'no_superfluous_phpdoc_tags'      => false,
        'phpdoc_separation'               => [
            'groups' => [
                [
                    'author',
                    'license',
                    'link',
                ],
            ],
        ],
        'no_alias_functions'              => true,
        'no_unneeded_control_parentheses' => true,
        'whitespace_after_comma_in_array' => [
            'ensure_single_space' => true,
        ],
        'single_line_throw'               => false,
        'self_accessor'                   => false,
        'global_namespace_import'         => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'function_declaration'            => [
            'closure_function_spacing' => 'one',
            'closure_fn_spacing'       => 'one',
        ],
        'binary_operator_spaces'          => [
            'operators' => [
                '='  => 'align_single_space_minimal',
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'yoda_style'                      => [
            'equal'                => false,
            'identical'            => false,
            'less_and_greater'     => false,
            'always_move_variable' => false,
        ],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('.build')
            ->exclude('config')
            ->exclude('node_modules')
            ->exclude('var')
            // TER cannot parse ext_emconf.php if it declares strict_types
            ->notPath('ext_emconf.php')
            ->in(__DIR__ . '/../')
    );
