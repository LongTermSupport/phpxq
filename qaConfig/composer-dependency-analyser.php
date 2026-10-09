<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * Project copy of the shipped default (vendor/lts/php-qa-ci/configDefaults/generic/composer-dependency-analyser.php)
 * with the two project-specific allowances below. The copy replaces the default outright.
 */
return new Configuration()
    // PHPStan, PHPArkitect, Rector and this analyser run from PHARs, so a qaConfig/ file that configures one
    // of them names classes Composer cannot autoload. That is the design, not a missing dependency.
    ->ignoreErrorsOnPath('qaConfig', [ErrorType::UNKNOWN_CLASS])
    // The rule tests extend PHPStan's RuleTestCase and use its node and reflection types. PHPStan is supplied by
    // the PHAR at analysis time and by phpstan/phpstan through the extension installer at test time, never by a
    // package this project requires.
    ->ignoreUnknownClassesRegex('~^PHPStan\\\\~')
    // The defence fixtures are analysed as text by the rule tests, never loaded; some name production classes
    // by the short name a rule matches on, not by a real namespace.
    ->ignoreErrorsOnPath('tests/Fixtures/Defence', [ErrorType::UNKNOWN_CLASS]);
