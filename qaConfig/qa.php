<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use QaConfig\ChangelogLaneSwitch;

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
    // PROVISIONAL measured floors, pending the first complete nightly run (.github/workflows/mutation-nightly.yml
    // prints the MSI it measures): the score the unit suite earns on its own (the conformance gate records no
    // coverage), rounded down. 89.2% was measured over only the first 21,104 of 26,219 mutants, where Infection
    // stopped on a process timeout. They only move up; the target is 90, tracked in plan 00011 with the line,
    // method and skipped-mutant floors in scripts/check-qa-measurements.bash.
    ->withInfectionFloors(89, 89)
    // CI sets PHPXQ_INFECTION_SKIP=1 when scripts/mutation-scope.bash finds the change maps to no source file.
    ->withInfection('1' !== getenv('PHPXQ_INFECTION_SKIP'))
    // Declarations carrying a native type, per kind: every one does. A resource is declared `mixed` with a
    // `@param resource` / `@return resource` tag, as PHP has no native resource type.
    ->withTypeCoverageFloors(returnType: 100, paramType: 100, propertyType: 100, constantType: 100)
    // Members nothing reaches are reported. The one PHP entry point outside src/ and tests/ is the executable.
    ->withDeadCodeDetection(true)
    ->withDeadCodeEntryPoints('bin/phpxq')
    // A change to anything a user receives or that decides how a release is built must be recorded under
    // `## Unreleased` in CHANGELOG.md (or carry a `Changelog: none — <reason>` commit trailer). The recorded
    // headings also decide the next version: docs/RELEASING.md. The lane is off on `main` itself, where it
    // could only look for an `85.N.N` tag that phpxq never creates (qaConfig/ChangelogLaneSwitch.php).
    ->withChangelogCheck(new ChangelogLaneSwitch('main')->appliesTo(__DIR__ . '/../.git'))
    ->withChangelogWatchedPaths('src/', 'bin/', 'scripts/', 'packaging/', 'composer.json', 'box.json', 'install.sh')
;
