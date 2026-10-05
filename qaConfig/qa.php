<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

/**
 * Project QA configuration (see vendor/lts/php-qa-ci/templates/qaConfig-qa.php).
 *
 * Related overrides in this directory:
 * - rector-safe.php: no Safe-function conversion (no production dependencies).
 * - composerRequireChecker.json: no thecodingmachine/safe scan files, for the same reason.
 */
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    // A jq/yq equivalent is a data-processing CLI: it handles no passwords, tokens or other secrets.
    ->withSensitiveParameterCheck(false)
    // Vendored upstream shell suites (jq shtest, yq acceptance scripts and shunit2) are byte-for-byte
    // copies kept unmodified for conformance; they are third-party code and must not be linted or fixed.
    ->withIgnoredPaths('tests/Conformance/Jq/shell', 'tests/Conformance/Yq/acceptance')
    // Fixtures of the static defences' own tests (qaConfig/PHPStan/Rules): each is deliberately an instance of a
    // bug class, so the fixers must not rewrite it and PHPStan must not report it.
    ->withIgnoredPaths('tests/Fixtures/Defence')
    // Mutation-score ratchet: both floors sit at the score the suite earns today (never 100, which would
    // make every legitimately equivalent mutant a failure), so a change cannot quietly weaken the tests.
    ->withInfectionFloors(90, 90)
    // Declarations carrying a native type, per kind: a floor only ever moves up.
    ->withTypeCoverageFloors(returnType: 95, paramType: 95, propertyType: 95, constantType: 95)
    // Members nothing reaches are reported. The one PHP entry point outside src/ and tests/ is the executable.
    ->withDeadCodeDetection(true)
    ->withDeadCodeEntryPoints('bin/phpxq')
;
